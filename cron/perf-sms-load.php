<?php
/**
 * ELLSMS — end-to-end SMS load-test harness (Phase 9B).
 *
 * Drives the REAL pipeline — bulk job, atomic claim, batched provider dispatch, delivery-status
 * polling — against the mock gateway, and reports what actually happened rather than what the code
 * believes happened. Provider request counts come from the MOCK'S OWN request log, not from an
 * internal counter: the one-request-per-recipient defect Phase 9A fixed was invisible to every
 * internal measure while it was issuing a million requests, and a harness that trusts internal
 * counters would have missed it too.
 *
 * DISTINCT FROM cron/load-test.php, which predates the gateway work and exercises the LEGACY fake
 * backend with N worker OS processes to measure queue throughput. This one exercises the GATEWAY
 * path and answers a different question: how many provider requests does N recipients become, and
 * where does the time go. Both are useful; neither replaces the other.
 *
 * MOCK ONLY. Refuses to run against a database that does not look disposable, refuses a
 * non-loopback gateway endpoint, and never carries provider credentials. No real SMS can leave.
 *
 *   php cron/perf-sms-load.php --recipients=1000
 *   php cron/perf-sms-load.php --recipients=10000 --provider-batch=200 --worker-claim=500
 *   php cron/perf-sms-load.php --recipients=1000 --mock-mode=MIXED --no-status
 *
 * Options (all optional):
 *   --recipients=N       recipients to queue                        (default 1000)
 *   --mode=bulk          workload shape: bulk (one body) | p2p (a body per row)  (default bulk)
 *   --gateway=mock       only 'mock' is accepted                    (default mock)
 *   --provider-batch=N   SMS_PROVIDER_BATCH_SIZE for this run       (default 200)
 *   --worker-claim=N     WORKER_BULK_BATCH_SIZE for this run        (default = provider-batch)
 *   --import-chunk=N     rows per insert batch when seeding         (default 5000)
 *   --mock-mode=NAME     SUCCESS | MIXED | SLOW | PENDING | ...     (default SUCCESS)
 *   --mock-latency=MS    artificial per-request provider latency    (default 0)
 *   --no-status          skip the delivery-status phase
 *   --keep               leave seeded rows behind for inspection
 *   --label=TEXT         free text recorded in the artifact
 *   --json               print ONLY the result JSON (for scripting)
 */

$root = dirname(__DIR__);

// Same ELLSMS_TEST_DB_* convention the integration suite and cron/load-test.php use, so one env
// serves all three.
$testHost = getenv('ELLSMS_TEST_DB_HOST');
if ($testHost !== false && $testHost !== '' && getenv('BACKEND_DB_HOST') === false) {
    putenv('BACKEND_DB_HOST=' . $testHost);
    putenv('BACKEND_DB_PORT=' . (getenv('ELLSMS_TEST_DB_PORT') ?: '3306'));
    putenv('BACKEND_DB_NAME=' . (getenv('ELLSMS_TEST_DB_NAME') ?: 'ellsms_test'));
    putenv('BACKEND_DB_USER=' . (getenv('ELLSMS_TEST_DB_USER') ?: 'ellsms_test'));
    putenv('BACKEND_DB_PASS=' . (getenv('ELLSMS_TEST_DB_PASS') ?: 'ellsms_test'));
}
putenv('APP_ENV=testing');

require_once $root . '/app/backend.php';

/* ---------------------------------------------------------------- options ---------------- */

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    }
}
function opt(array $o, string $k, string $default): string { return (string)($o[$k] ?? $default); }

$recipients   = max(1, (int)opt($opts, 'recipients', '1000'));
$workload     = strtolower(opt($opts, 'mode', 'bulk'));
$gatewayKind  = strtolower(opt($opts, 'gateway', 'mock'));
$providerBatch= max(1, (int)opt($opts, 'provider-batch', '200'));
$workerClaim  = max(1, (int)opt($opts, 'worker-claim', (string)$providerBatch));
$importChunk  = max(100, (int)opt($opts, 'import-chunk', '5000'));
$mockMode     = strtolower(opt($opts, 'mock-mode', 'success'));
$mockLatency  = max(0, (int)opt($opts, 'mock-latency', '0'));
$runStatus    = !isset($opts['no-status']);
$keep         = isset($opts['keep']);
$label        = opt($opts, 'label', $recipients . 'r');
$jsonOnly     = isset($opts['json']);

if ($gatewayKind !== 'mock') {
    fwrite(STDERR, "REFUSING: --gateway must be 'mock'. This harness never sends through a real provider.\n");
    exit(1);
}

/* ---------------------------------------------------------------- safety ----------------- */

$dbName = (string)env('BACKEND_DB_NAME', '');
if (!str_contains(strtolower($dbName), 'test') && env('ELLSMS_ALLOW_LOAD_TEST', '0') !== '1') {
    fwrite(STDERR, "REFUSING TO RUN: BACKEND_DB_NAME (\"{$dbName}\") does not look like a disposable test database.\n");
    fwrite(STDERR, "Point this at a real disposable database, or set ELLSMS_ALLOW_LOAD_TEST=1 if you are certain.\n");
    exit(1);
}

$say = static function (string $line) use ($jsonOnly): void {
    if (!$jsonOnly) { fwrite(STDOUT, $line . "\n"); }
};

/* ---------------------------------------------------------------- mock gateway ----------- */

// A loopback port, chosen by the OS so concurrent runs cannot collide.
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    fwrite(STDERR, "Could not allocate a local port: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($sock, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($sock);

$runId       = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$requestLog  = sys_get_temp_dir() . '/ellsms_perf_' . $runId . '.jsonl';
$baseUrl     = 'http://127.0.0.1:' . $port;

$env = getenv();
$env['MOCK_SMS_REQUEST_LOG'] = $requestLog;
$env['MOCK_SMS_MODE']        = $mockMode;
$env['MOCK_SMS_LATENCY_MS']  = (string)$mockLatency;

$mock = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/mock/gateway.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root . '/mock',
    $env
);
if ($mock === false) {
    fwrite(STDERR, "Could not start the mock gateway.\n");
    exit(1);
}
$deadline = microtime(true) + 8;
$up = false;
while (microtime(true) < $deadline) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); $up = true; break; }
    usleep(50000);
}
if (!$up) {
    proc_terminate($mock);
    fwrite(STDERR, "Mock gateway did not become reachable.\n");
    exit(1);
}
$say("mock gateway: {$baseUrl}  (mode={$mockMode}, latency={$mockLatency}ms)");

// From here on, always tear the mock down — a stranded PHP server holding a port is a nasty thing
// to leave behind on a developer's machine.
$cleanupMock = static function () use ($mock, $requestLog): void {
    @proc_terminate($mock);
    @proc_close($mock);
    @unlink($requestLog);
};

/* ---------------------------------------------------------------- run knobs -------------- */

putenv('SMS_GATEWAY_TRANSPORT=1');
putenv('ELLSMS_MOCK_GATEWAY_ENABLED=1');
putenv('SMS_PROVIDER_BATCH_SIZE=' . $providerBatch);
putenv('WORKER_BULK_BATCH_SIZE=' . $workerClaim);

$db = db();
$suffix = bin2hex(random_bytes(4));
$sender = '5000' . random_int(100000, 999999);

$seeded = ['user' => null, 'org' => null, 'gateway' => null, 'provider' => null, 'route' => null, 'job' => null];

$fail = static function (string $msg) use ($cleanupMock): void {
    $cleanupMock();
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

/* ---------------------------------------------------------------- seed ------------------- */

$t0 = microtime(true);

try {
    // Admin owner: originator authorization has its own coverage and is not what this measures.
    $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['perf_' . $suffix]);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,1,?)')
       ->execute([$userId, $sender]);
    $seeded['user'] = $userId;

    $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
       ->execute(['perf load', 'perf-' . $suffix, $userId]);
    $orgId = (int)$db->lastInsertId();
    $seeded['org'] = $orgId;

    // A batch-mode gateway flagged is_mock, pointed at the loopback mock. is_mock is what keeps this
    // configuration unusable in production unless ELLSMS_MOCK_GATEWAY_ENABLED=1 (Phase 6).
    $code = 'perf_' . $suffix;
    $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, is_mock, config_version)
                  VALUES (?,?, 'active','batch',1,1,0,1,1)")->execute([$code, $code]);
    $gatewayId = (int)$db->lastInsertId();
    $seeded['gateway'] = $gatewayId;

    // Connector configuration is copied from cron/mock-gateway-seed.php rather than reinvented:
    // that is the configuration the mock gateway is known to answer correctly, and a benchmark that
    // quietly used a different one would be measuring a setup nobody runs.
    $db->prepare(
        "INSERT INTO ellsms_sms_gateway_send_connectors
           (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, response_mapping_json, batch_mapping_json)
         VALUES (?,?, 'POST','application/json',5000,60000,0,'none',?,?,?)"
    )->execute([
        $gatewayId, $baseUrl . '/send',
        json_encode(['http' => ['min' => 200, 'max' => 299], 'rules' => [['source' => 'body', 'path' => 'success', 'operator' => 'equals', 'values' => [true]]]], JSON_UNESCAPED_UNICODE),
        json_encode(['provider_message_id' => 'references.0'], JSON_UNESCAPED_UNICODE),
        // POSITIONAL correlation: request index N maps to references[N]. Exercising this is part of
        // the point — it is the mapping a real batch provider needs.
        json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'], JSON_UNESCAPED_UNICODE),
    ]);

    foreach ([
        ['originators',  'senders_array',    'numeric_array', 10],
        ['destinations', 'recipients_array', 'string_array',  20],
        ['contents',     'messages_array',   'string_array',  30],
    ] as [$k, $v, $dt, $so]) {
        $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
              (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
            VALUES (?, 'send','body','gateway',NULL,?, 'variable',?,?, 'active',?,?)")
           ->execute([$gatewayId, $k, $v, $dt, $so, "{$gatewayId}:send:body:gateway::{$k}"]);
    }

    // The gateway must carry every operator, or destinations on an unassigned network are refused.
    $assign = $db->prepare("INSERT IGNORE INTO ellsms_sms_gateway_operators (gateway_id, operator_id, status) VALUES (?,?,'active')");
    foreach ($db->query("SELECT id FROM ellsms_sms_operators WHERE status='active'")->fetchAll() as $op) {
        $assign->execute([$gatewayId, (int)$op['id']]);
    }

    // Status connector, so the delivery-status phase exercises the real poller.
    $db->prepare(
        "INSERT INTO ellsms_sms_gateway_status_connectors
           (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type,
            success_rule_json, response_mapping_json, status_mapping_json, poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
         VALUES (?,?, 'POST','application/json',5000,30000,0,'none',?,?,?,0,5,86400)"
    )->execute([
        $gatewayId, $baseUrl . '/status',
        json_encode(['rules' => [['source' => 'body', 'path' => 'error_code', 'operator' => 'equals', 'values' => [0]]]], JSON_UNESCAPED_UNICODE),
        json_encode(['provider_status' => 'state', 'items_path' => 'states', 'id_path' => 'id', 'status_path' => 'state'], JSON_UNESCAPED_UNICODE),
        // Canonical delivery statuses only (GATEWAY_DELIVERY_STATUSES). An unknown value makes the
        // gateway fail to compile and the send silently falls back to the legacy path — which is
        // exactly how a benchmark ends up measuring the wrong thing.
        json_encode(['1' => 'accepted', '2' => 'sent', '3' => 'delivered', '4' => 'failed', '5' => 'queued'], JSON_UNESCAPED_UNICODE),
    ]);
    $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
          (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
        VALUES (?, 'status','body','gateway',NULL,'reference_ids','variable','provider_message_ids','integer_list','active',0,?)")
       ->execute([$gatewayId, "{$gatewayId}:status:body:gateway::reference_ids"]);

    $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['pp_' . $suffix, 'perf', 'active']);
    $providerId = (int)$db->lastInsertId();
    $seeded['provider'] = $providerId;
    $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
       ->execute([$providerId, 'pr_' . $suffix, 'perf', 'default', 'active', $gatewayId]);
    $routeId = (int)$db->lastInsertId();
    $seeded['route'] = $routeId;
    $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
       ->execute([$sender, 'default', $routeId, 'active', $sender . ':default']);

    // A price for the route, so pricing resolution succeeds. The owner is an admin and therefore not
    // charged, but the send path still resolves a price and a missing one would refuse the send.
    $db->prepare(
        "INSERT INTO ellsms_sms_route_prices (route_id, operator_id, price_per_segment_millicredits, currency, effective_from, status)
         VALUES (?, NULL, 1000, 'credit', ?, 'active')"
    )->execute([$routeId, date('Y-m-d H:i:s', time() - 86400)]);

    gateway_cache_reset();
    sms_pricing_cache_reset();

    // FAIL LOUDLY IF THE GATEWAY DID NOT COMPILE. A connector with one bad value (an unmapped
    // delivery status, say) fails to compile and the send path falls back to the legacy backend —
    // quietly. The run then "succeeds" while measuring something else entirely, which is the worst
    // possible outcome for a benchmark. Check it once, up front, before any timing starts.
    $compiled = gateway_compiled($gatewayId);
    if ($compiled === null) {
        $fail("The mock gateway failed to compile — the send path would silently fall back to the legacy backend and this benchmark would measure the wrong thing. See the gateway.compile_failed log line above for the reason.");
    }
    if (($compiled['send_mode'] ?? '') !== 'batch') {
        $fail("The mock gateway compiled as send_mode='" . (string)($compiled['send_mode'] ?? '?') . "', not 'batch'. Batching is what this harness exists to measure.");
    }

    /* ------------------------------------------------------------ import ----------------- */
    $importStart = microtime(true);

    $content = 'متن آزمایشی بارگذاری — تست کارایی';
    $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, template, total_rows, status)
                  VALUES (?,?,?,?,?,?, 'processing')")
       ->execute([$userId, $orgId, 'perf ' . $label, $sender, $content, $recipients]);
    $jobId = (int)$db->lastInsertId();
    $seeded['job'] = $jobId;

    // Chunked multi-row INSERT: the point of --import-chunk. Nothing accumulates in PHP.
    $written = 0;
    while ($written < $recipients) {
        $n = min($importChunk, $recipients - $written);
        $values = [];
        $params = [];
        for ($i = 0; $i < $n; $i++) {
            $idx = $written + $i;
            // No "000" run: several fixtures treat that as a rejection marker, and a generated
            // number that tripped one by accident would quietly skew a benchmark.
            $mobile = '98912' . str_pad((string)(1111111 + $idx), 7, '1', STR_PAD_LEFT);
            $rowContent = $workload === 'p2p' ? ($content . ' #' . $idx) : $content;
            $values[] = '(?,?,?,\'pending\')';
            array_push($params, $jobId, $mobile, $rowContent);
        }
        $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES ' . implode(',', $values))
           ->execute($params);
        $written += $n;
    }
    $importSeconds = microtime(true) - $importStart;
    $say(sprintf('import: %d rows in %.2fs (%s rows/sec)', $recipients, $importSeconds,
        number_format($importSeconds > 0 ? $recipients / $importSeconds : 0, 0)));

    /* ------------------------------------------------------------ analysis --------------- */
    // Pricing resolution for this job's route/operator mix. Measured separately because it is the
    // phase that scales with distinct (route, operator) pairs rather than with recipients.
    $analysisStart = microtime(true);
    sms_pricing_cache_reset();
    $sampleSt = $db->prepare('SELECT mobile FROM ellsms_bulk_items WHERE job_id = ? LIMIT 200');
    $sampleSt->execute([$jobId]);
    foreach ($sampleSt->fetchAll() as $row) {
        gateway_resolve_recipient_operator((string)$row['mobile']);
    }
    $analysisSeconds = microtime(true) - $analysisStart;

    /* ------------------------------------------------------------ send ------------------- */
    $sendStart = microtime(true);
    $sent = 0;
    $passes = 0;
    while (true) {
        $items = bulk_claim_items($db, 'j.id = ?', [$jobId], $workerClaim);
        if ($items === []) break;
        $passes++;
        $sent += bulk_send_claimed_items($db, $items);
        if ($passes > ($recipients / max(1, $workerClaim)) + 50) {
            $say('send: aborting, too many passes (rows are not reaching a terminal state)');
            break;
        }
    }
    $sendSeconds = microtime(true) - $sendStart;

    /* ------------------------------------------------------------ status ----------------- */
    $statusSeconds = 0.0;
    $statusPasses = 0;
    $delivered = 0;
    if ($runStatus) {
        $statusStart = microtime(true);
        for ($i = 0; $i < 30; $i++) {
            $stats = gateway_status_poll_pass();
            $statusPasses++;
            if ((int)($stats['claimed'] ?? 0) === 0) break;
        }
        $statusSeconds = microtime(true) - $statusStart;
        $st = $db->prepare("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = ? AND delivery_status = 'delivered'");
        $st->execute([$jobId]);
        $delivered = (int)$st->fetchColumn();
    }

    /* ------------------------------------------------------------ measure ---------------- */
    $totalSeconds = microtime(true) - $t0;

    $sendRequests = [];
    $statusRequests = [];
    $lostLogLines = 0;
    $unparsableLogLines = 0;
    foreach (array_filter(explode("\n", (string)@file_get_contents($requestLog))) as $line) {
        $d = json_decode($line, true);
        if (!is_array($d)) { $unparsableLogLines++; continue; }
        if (isset($d['lost']))                { $lostLogLines++; continue; }
        if (($d['path'] ?? '') === '/send')   { $sendRequests[] = (int)$d['count']; }
        if (($d['path'] ?? '') === '/status') { $statusRequests[] = (int)$d['count']; }
    }

    $byStatus = [];
    $q = $db->prepare('SELECT status, COUNT(*) c FROM ellsms_bulk_items WHERE job_id = ? GROUP BY status');
    $q->execute([$jobId]);
    foreach ($q->fetchAll() as $r) { $byStatus[(string)$r['status']] = (int)$r['c']; }

    $requestCount = count($sendRequests);
    $expected = (int)ceil($recipients / $providerBatch);

    $result = [
        'run_id'    => $runId,
        'label'     => $label,
        'generated' => date('c'),
        'config' => [
            'recipients'          => $recipients,
            'workload'            => $workload,
            'gateway'             => 'mock',
            'mock_mode'           => $mockMode,
            'mock_latency_ms'     => $mockLatency,
            'provider_batch_size' => $providerBatch,
            'worker_claim_size'   => $workerClaim,
            'import_chunk'        => $importChunk,
        ],
        'durations_seconds' => [
            'import'   => round($importSeconds, 3),
            'analysis' => round($analysisSeconds, 3),
            'send'     => round($sendSeconds, 3),
            'status'   => round($statusSeconds, 3),
            'total'    => round($totalSeconds, 3),
        ],
        'throughput' => [
            'import_rows_per_sec' => $importSeconds > 0 ? round($recipients / $importSeconds, 1) : null,
            'messages_per_sec'    => $sendSeconds > 0 ? round($sent / $sendSeconds, 1) : null,
        ],
        'provider' => [
            'send_requests'        => $requestCount,
            'expected_requests'    => $expected,
            'matches_expectation'  => $requestCount === $expected,
            'avg_batch_size'       => $requestCount > 0 ? round(array_sum($sendRequests) / $requestCount, 1) : null,
            'min_batch_size'       => $requestCount > 0 ? min($sendRequests) : null,
            'max_batch_size'       => $requestCount > 0 ? max($sendRequests) : null,
            'recipients_covered'   => array_sum($sendRequests),
            'status_requests'      => count($statusRequests),
            // Instrumentation health. A benchmark that can silently lose log lines under-reports
            // its request count, which reads as better-than-real batching. These make any such gap
            // explicit instead of invisible.
            'log_lines_lost'       => $lostLogLines,
            'log_lines_unparsable' => $unparsableLogLines,
            // The DATABASE is authoritative for what sent; the log is the observed HTTP record.
            // When they disagree, this is the number that matters.
            'requests_implied_by_db' => $providerBatch > 0 ? (int)ceil($sent / $providerBatch) : null,
        ],
        'outcome' => [
            'items_sent'    => $sent,
            'item_status'   => $byStatus,
            'delivered'     => $delivered,
            'worker_passes' => $passes,
            'status_passes' => $statusPasses,
        ],
        'memory' => [
            'peak_mb'      => round(memory_get_peak_usage(true) / 1048576, 1),
            'peak_real_mb' => round(memory_get_peak_usage(false) / 1048576, 1),
        ],
    ];

    // Say WHY the count differs rather than asserting a number. Three distinct causes, and
    // conflating them would make the benchmark lie in opposite directions.
    if ($requestCount !== $expected) {
        $covered = array_sum($sendRequests);
        if ($requestCount > $expected) {
            // MORE requests than recipients/batch: groups genuinely fragmented.
            $result['provider']['grouping_note'] = $workload === 'p2p'
                ? 'p2p workload: each row carries its own message body, and one request carries one body, so rows do not share requests.'
                : 'more requests than recipients/batch — check for per-recipient parameters, a per_message connector, or a mixed operator set fragmenting groups.';
        } elseif ($covered < $sent) {
            // FEWER requests, and the logged recipients do not account for everything that sent.
            // The mock's request log is a best-effort append (@file_put_contents); under a long run
            // an occasional line can be lost. The DB is authoritative for what was SENT, so report
            // the discrepancy instead of quietly under-reporting the request count.
            $missing = $sent - $covered;
            $result['provider']['log_fidelity_note'] = sprintf(
                'the mock request log accounts for %s of %s sent recipients (%s unlogged, ~%d request(s)); '
                . 'the database is authoritative for what sent, the log is best-effort. '
                . 'Retries observed: check outcome.item_status — none means the sends happened and only log lines were lost.',
                number_format($covered), number_format($sent), number_format($missing),
                (int)ceil($missing / max(1, $providerBatch))
            );
        } else {
            $result['provider']['grouping_note'] =
                'fewer requests than recipients/batch — the final claim returned a partial batch, which is normal.';
        }
    }

    $benchDir = $root . '/storage/benchmarks';
    if (!is_dir($benchDir)) { @mkdir($benchDir, 0750, true); }
    $artifact = $benchDir . '/perf-sms-' . $runId . '-' . preg_replace('/[^a-z0-9_-]/i', '_', $label) . '.json';
    @file_put_contents($artifact, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $result['artifact'] = $artifact;

    if ($jsonOnly) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        $say('');
        $say('--- Result ---');
        $say(sprintf('recipients        %d', $recipients));
        $say(sprintf('provider requests %d  (expected ~%d)%s', $requestCount, $expected, $requestCount === $expected ? '' : '  <-- see grouping_note'));
        $say(sprintf('avg batch         %s  (min %s, max %s)',
            (string)($result['provider']['avg_batch_size'] ?? '-'),
            (string)($result['provider']['min_batch_size'] ?? '-'),
            (string)($result['provider']['max_batch_size'] ?? '-')));
        $say(sprintf('send              %.2fs  (%s msg/sec)', $sendSeconds, (string)($result['throughput']['messages_per_sec'] ?? '-')));
        $say(sprintf('import            %.2fs  (%s rows/sec)', $importSeconds, (string)($result['throughput']['import_rows_per_sec'] ?? '-')));
        $say(sprintf('status            %.2fs  (%d requests, %d delivered)', $statusSeconds, count($statusRequests), $delivered));
        $say(sprintf('total             %.2fs', $totalSeconds));
        $say(sprintf('peak memory       %s MB', (string)$result['memory']['peak_mb']));
        $say(sprintf('item status       %s', json_encode($byStatus)));
        $say('artifact: ' . $artifact);
    }
} catch (Throwable $t) {
    $cleanupMock();
    fwrite(STDERR, 'load test failed: ' . $t->getMessage() . "\n" . $t->getTraceAsString() . "\n");
    exit(1);
}

/* ---------------------------------------------------------------- cleanup ---------------- */

if (!$keep) {
    try {
        if ($seeded['job']) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$seeded['job']]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$seeded['job']]);
        }
        if ($seeded['user']) {
            $db->prepare('DELETE FROM ellsms_message_attempts WHERE user_id = ?')->execute([$seeded['user']]);
            $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$seeded['user']]);
            $db->prepare('DELETE FROM ellsms_wallet_reservations WHERE user_id = ?')->execute([$seeded['user']]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$seeded['user']]);
        }
        $db->prepare('DELETE FROM ellsms_sender_routes WHERE sender = ?')->execute([$sender]);
        // Prices reference the route, so they must go first or the route delete hits the FK.
        if ($seeded['route']) {
            $db->prepare('DELETE FROM ellsms_sms_route_prices WHERE route_id = ?')->execute([$seeded['route']]);
            $db->prepare('DELETE FROM ellsms_sms_price_snapshots WHERE reference_type = ?')->execute(['bulk_job']);
        }
        if ($seeded['gateway']) {
            $db->prepare('DELETE FROM ellsms_sms_gateway_operators WHERE gateway_id = ?')->execute([$seeded['gateway']]);
        }
        if ($seeded['route'])    { $db->prepare('DELETE FROM ellsms_sms_routes WHERE id = ?')->execute([$seeded['route']]); }
        if ($seeded['provider']) { $db->prepare('DELETE FROM ellsms_sms_providers WHERE id = ?')->execute([$seeded['provider']]); }
        if ($seeded['gateway']) {
            $db->prepare('DELETE FROM ellsms_sms_gateway_parameters WHERE gateway_id = ?')->execute([$seeded['gateway']]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_send_connectors WHERE gateway_id = ?')->execute([$seeded['gateway']]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_status_connectors WHERE gateway_id = ?')->execute([$seeded['gateway']]);
            $db->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$seeded['gateway']]);
        }
        if ($seeded['org'])  { $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$seeded['org']]); }
        if ($seeded['user']) {
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$seeded['user']]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$seeded['user']]);
        }
    } catch (Throwable $t) {
        $say('cleanup warning: ' . $t->getMessage());
    }
}

$cleanupMock();
exit(0);
