<?php
/**
 * #46 — SMPP gateways (docs/smpp-gateway.md).
 *
 * A gateway whose protocol is 'smpp' has no HTTP connector: its sessions live in the smpp-bridge
 * container (smpp-bridge/, Java/jSMPP), which reads the same gateway configuration from the shared
 * database. This file is the PHP half:
 *
 *   - smpp_gateway_compile()  the compiled connector for an SMPP gateway (same cache/versioning as HTTP)
 *   - smpp_gateway_send()     gateway_send() for SMPP: one call to the bridge's /v1/submit, answered
 *                             in exactly the shape gateway_send() returns, so bulk, direct sends,
 *                             wallet settlement and retries behave identically for both protocols
 *   - smpp_events_process_pass()  applies delivery receipts and stores received messages that the
 *                             bridge wrote to ellsms_smpp_events (run by the status-worker)
 *   - smpp_bridge_*()         the bridge's internal HTTP API (status, reconnect, test bind)
 */

declare(strict_types=1);

const SMPP_BRIDGE_MAX_RECIPIENTS = 500;

function smpp_bridge_url(): string {
    return rtrim((string)(env('SMPP_BRIDGE_URL', 'http://smpp-bridge:8090') ?? 'http://smpp-bridge:8090'), '/');
}

/**
 * One request to the bridge. The bridge is an internal service on the Docker network, called with a
 * fixed base URL from the environment — never an admin-supplied address — so it deliberately does not
 * go through gateway_endpoint_allowed()'s public-address rules.
 *
 * @return array{ok: bool, http: int, data: mixed, error: ?string, timed_out: bool}
 */
function smpp_bridge_request(string $method, string $path, ?array $body = null, int $timeoutMs = 60000): array {
    $ch = curl_init(smpp_bridge_url() . $path);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . (string)env('SMPP_BRIDGE_TOKEN', '')];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'smpp-bridge unreachable: ' . $error, 'timed_out' => $errno === CURLE_OPERATION_TIMEDOUT];
    }
    $data = json_decode((string)$raw, true);
    $ok = $http >= 200 && $http < 300 && is_array($data);
    return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $ok ? null : (is_array($data) ? (string)($data['detail'] ?? $data['error'] ?? 'error') : 'invalid response'), 'timed_out' => false];
}

/** The live view of every gateway/session the bridge runs (null when unreachable). */
function smpp_bridge_status(): ?array {
    $r = smpp_bridge_request('GET', '/v1/status', null, 5000);
    return $r['ok'] ? $r['data'] : null;
}

/* ==========================================================================
   Compile + send
   ========================================================================== */

/** The SMPP settings row for a gateway, or null. */
function smpp_connector_row(int $gatewayId): ?array {
    try {
        $st = db()->prepare('SELECT * FROM ellsms_sms_gateway_smpp_connectors WHERE gateway_id = ?');
        $st->execute([$gatewayId]);
        return $st->fetch() ?: null;
    } catch (PDOException) {
        return null; // 2026_10_06_smpp_gateway.sql not applied
    }
}

/**
 * Called by gateway_compile() for protocol = 'smpp'. Same top-level shape as an HTTP connector so every
 * caller (route resolution, operator support, health checks, the panel) works unchanged; 'send' carries
 * no HTTP request description, only the recipient cap and a smpp:// endpoint for the active TCP health
 * probe. Delivery state arrives as receipts, never by polling, so 'status' is null.
 */
function smpp_gateway_compile(array $gateway): ?array {
    $gatewayId = (int)$gateway['id'];
    $row = smpp_connector_row($gatewayId);
    if ($row === null || trim((string)$row['host']) === '') {
        Logger::error('gateway.compile_failed', ['gateway_id' => $gatewayId, 'reason' => 'no_smpp_connector']);
        return null;
    }
    $st = db()->prepare("SELECT o.id, o.code FROM ellsms_sms_gateway_operators go JOIN ellsms_sms_operators o ON o.id = go.operator_id
                         WHERE go.gateway_id = ? AND go.status = 'active'");
    $st->execute([$gatewayId]);
    $operators = [];
    foreach ($st->fetchAll() as $op) $operators[(int)$op['id']] = (string)$op['code'];
    gateway_counter_increment('compile');
    $empty = ['gateway' => [], 'route' => [], 'operator' => []];
    return [
        'gateway_id' => $gatewayId,
        'gateway_code' => (string)$gateway['code'],
        'config_version' => (int)$gateway['config_version'],
        'is_mock' => (bool)($gateway['is_mock'] ?? false),
        'protocol' => 'smpp',
        'send_mode' => 'batch',
        'send_enabled' => (bool)$gateway['send_enabled'],
        'status_enabled' => false,
        'receive_enabled' => false,
        'operators' => $operators,
        'send' => [
            'endpoint' => 'smpp://' . $row['host'] . ':' . (int)$row['port'],
            'batch' => ['max_recipients' => SMPP_BRIDGE_MAX_RECIPIENTS],
            'parameters' => $empty,
        ],
        'status' => null,
        'receive' => null,
        'smpp' => $row,
    ];
}

/** gateway_send() for an SMPP gateway — same input, same result shape. */
function smpp_gateway_send(array $connector, array $input, ?int $routeId, ?int $operatorId = null): array {
    $destinations = array_values(array_map('strval', $input['recipients'] ?? []));
    if ($destinations === []) return gateway_send_failure('no destinations', BackendError::REJECTED);
    if (empty($connector['send_enabled'])) {
        $off = gateway_send_failure('ارسال این درگاه خاموش است.', BackendError::UNAVAILABLE);
        $off['retryable'] = true;
        return $off;
    }

    $carried = [];
    $operators = [];
    foreach ($destinations as $destination) {
        $operator = $operatorId !== null ? ['operator_id' => $operatorId] : gateway_resolve_recipient_operator($destination);
        if (!gateway_supports_operator($connector, $operator['operator_id'])) continue;
        $carried[] = $destination;
        $operators[$destination] = $operator['operator_id'];
    }
    if ($carried === []) return gateway_send_failure('gateway does not carry this operator', BackendError::REJECTED);

    $perRecipient = is_array($input['messages'] ?? null) ? $input['messages'] : null;
    $sent = [];
    $messageIds = [];
    $lastError = null;
    $lastClass = null;
    $lastHttp = 0;
    $retryable = false;
    $groups = 0;
    foreach (array_chunk($carried, SMPP_BRIDGE_MAX_RECIPIENTS) as $chunk) {
        $groups++;
        $body = [
            'gateway_id' => (int)$connector['gateway_id'],
            'sender' => (string)($input['sender'] ?? ''),
            'recipients' => $chunk,
            'message' => (string)($input['message'] ?? ''),
        ];
        if ($perRecipient !== null) {
            $body['messages'] = array_intersect_key($perRecipient, array_flip($chunk));
        }
        $started = microtime(true);
        // Generous timeout: the bridge answers once every recipient's submit_sm_resp is in, paced by TPS.
        $timeoutMs = 30000 + (int)ceil(count($chunk) * 1000 / max(1, (int)($connector['smpp']['tps'] ?? 50))) * 2;
        $r = smpp_bridge_request('POST', '/v1/submit', $body, min(600000, $timeoutMs));
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $lastHttp = $r['http'];
        $tags = ['gateway' => $connector['gateway_code'], 'connector' => 'smpp'];
        if (!$r['ok']) {
            $lastError = (string)$r['error'];
            $lastClass = $r['timed_out'] ? BackendError::TIMEOUT : ($r['http'] === 401 ? BackendError::UNAUTHORIZED : ($r['http'] === 400 ? BackendError::REJECTED : BackendError::UNAVAILABLE));
            $retryable = $retryable || BackendError::isRetryable($lastClass) || $r['http'] === 503;
            Logger::error('gateway.smpp.submit_failed', ['gateway_id' => $connector['gateway_id'], 'http' => $r['http'], 'error' => $lastError, 'elapsed_ms' => $elapsed]);
            Metrics::increment('gateway_send_failure', 1, $tags + ['error_class' => $lastClass]);
            continue;
        }
        foreach ((array)($r['data']['results'] ?? []) as $result) {
            $to = (string)($result['recipient'] ?? '');
            if (!in_array($to, $chunk, true)) continue;
            if (!empty($result['ok']) && ($id = gateway_provider_message_id_normalize($result['message_id'] ?? null)) !== null) {
                $sent[] = $to;
                $messageIds[$to] = $id;
                continue;
            }
            $lastError = (string)($result['error'] ?? 'SMPP submit failed');
            $lastClass = match ((string)($result['error_class'] ?? '')) {
                'timeout' => BackendError::TIMEOUT,
                'unavailable' => BackendError::UNAVAILABLE,
                default => BackendError::REJECTED,
            };
            $retryable = $retryable || BackendError::isRetryable($lastClass);
        }
        Logger::info('gateway.request_completed', ['gateway_id' => $connector['gateway_id'], 'config_version' => $connector['config_version'], 'connector' => 'smpp', 'recipients' => count($chunk), 'accepted' => count($sent), 'elapsed_ms' => $elapsed]);
        Metrics::timing('gateway_request', $elapsed, $tags + ['result' => $sent !== [] ? 'success' : 'failed']);
        Metrics::increment('gateway_send_total', 1, $tags);
    }
    if ($sent === [] && $lastClass === null) {
        $lastError = 'gateway rejected every destination';
        $lastClass = BackendError::REJECTED;
    }
    return [
        'ok' => $sent !== [], 'sent' => $sent, 'message_ids' => $messageIds,
        'error' => $sent === [] ? $lastError : null, 'error_class' => $sent === [] ? $lastClass : null,
        'http' => $lastHttp, 'retryable' => $sent === [] ? $retryable : false, 'groups' => $groups, 'operators' => $operators,
        'normalized_outcome' => $sent !== [] ? PROVIDER_RESPONSE_SUCCESS : PROVIDER_RESPONSE_FAILED,
        'provider_error_code' => null, 'provider_error_detail' => $sent === [] ? $lastError : null,
    ];
}

/* ==========================================================================
   Receipts and received messages (ellsms_smpp_events)
   ========================================================================== */

/** SMPP receipt "stat" → the canonical delivery state shared with polled HTTP gateways. */
function smpp_dlr_canonical(?string $stat): string {
    return match (strtoupper(trim((string)$stat))) {
        'DELIVRD', 'DELIVERED' => 'delivered',
        'UNDELIV', 'UNDELIVERABLE', 'DELETED' => 'failed',
        'EXPIRED' => 'expired',
        'REJECTD', 'REJECTED' => 'rejected',
        'ACCEPTD', 'ACCEPTED' => 'accepted',
        'ENROUTE' => 'sent',
        default => 'unknown',
    };
}

/**
 * The provider ids a receipt id may correspond to. SMSCs disagree on whether the receipt repeats the
 * submit_sm_resp id as-is, in decimal, or in hex — 'auto' tries all of them, the other formats pin one.
 *
 * @return list<string>
 */
function smpp_message_id_variants(string $id, string $format = 'auto'): array {
    $id = trim($id);
    if ($id === '') return [];
    $out = [];
    if ($format === 'as_is' || $format === 'auto') $out[] = $id;
    if (($format === 'hex_to_dec' || $format === 'auto') && ctype_xdigit($id) && strlen($id) <= 30) {
        $out[] = smpp_base_convert($id, 16, 10);
    }
    if (($format === 'dec_to_hex' || $format === 'auto') && ctype_digit($id) && strlen($id) <= 30) {
        $hex = smpp_base_convert($id, 10, 16);
        $out[] = strtoupper($hex);
        $out[] = strtolower($hex);
    }
    if ($format === 'auto' && ctype_digit($id)) $out[] = ltrim($id, '0') === '' ? '0' : ltrim($id, '0');
    return array_values(array_unique($out));
}

/** Arbitrary-precision base conversion for ids beyond 2^53 (hexdec() would lose digits). */
function smpp_base_convert(string $number, int $from, int $to): string {
    $digits = '0123456789abcdef';
    $number = strtolower($number);
    $result = '';
    $values = array_map(static fn(string $c): int => strpos($digits, $c), str_split($number));
    while ($values !== []) {
        $remainder = 0;
        $next = [];
        foreach ($values as $v) {
            $acc = $remainder * $from + $v;
            $q = intdiv($acc, $to);
            $remainder = $acc % $to;
            if ($next !== [] || $q > 0) $next[] = $q;
        }
        $result = $digits[$remainder] . $result;
        $values = $next;
    }
    return $result === '' ? '0' : $result;
}

/**
 * A received message's destination as ELLSMS stores lines: SMSCs often send it with the country code
 * (98300012 for line 300012). The prefix is removed only when the shorter form is an actual line.
 */
function smpp_mo_destination(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    foreach (['0098', '98'] as $prefix) {
        if (str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix) + 3) {
            $short = substr($digits, strlen($prefix));
            $st = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE number = ? LIMIT 1');
            $st->execute([$short]);
            if ($st->fetchColumn()) return $short;
        }
    }
    return $digits !== '' ? $digits : $raw;
}

/**
 * Applies pending events. A receipt can arrive before the send that produced it has recorded its
 * provider id (the SMSC is fast, the worker commits after the whole batch), so an unmatched receipt is
 * retried with backoff for ~1 hour before it is marked 'unmatched'.
 *
 * @return array{processed: int, updated: int, unchanged: int, unmatched: int, retry: int, mo_stored: int}
 */
function smpp_events_process_pass(int $limit = 500): array {
    $stats = ['processed' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'retry' => 0, 'mo_stored' => 0];
    $db = db();
    try {
        $st = $db->prepare(
            'SELECT * FROM ellsms_smpp_events
              WHERE processed_at IS NULL
                AND created_at <= DATE_SUB(NOW(), INTERVAL LEAST(600, attempts * attempts * 10) SECOND)
              ORDER BY id LIMIT ' . max(1, $limit)
        );
        $st->execute();
        $events = $st->fetchAll();
    } catch (PDOException) {
        return $stats; // 2026_10_06_smpp_gateway.sql not applied yet
    }
    $formats = [];
    foreach ($events as $event) {
        // Claim: two status-workers never apply the same event.
        $claim = $db->prepare("UPDATE ellsms_smpp_events SET processed_at = NOW(), result = 'processing' WHERE id = ? AND processed_at IS NULL");
        $claim->execute([$event['id']]);
        if ($claim->rowCount() !== 1) continue;
        $gatewayId = (int)$event['gateway_id'];
        try {
            if ($event['event_type'] === 'mo') {
                $newId = gateway_receive_store($db, $gatewayId, [
                    'originator' => mb_substr(preg_replace('/\D/', '', (string)$event['source']) ?: (string)$event['source'], 0, 20),
                    'destination' => mb_substr(smpp_mo_destination((string)$event['destination']), 0, 20),
                    'content' => (string)$event['content'],
                    'received_at' => (string)$event['received_at'],
                    'provider_message_id' => 'smpp-event:' . $event['id'],
                ]);
                $result = $newId !== null ? 'stored' : 'duplicate';
                if ($newId !== null) {
                    $stats['mo_stored']++;
                    Metrics::increment('gateway.inbound.stored', 1, ['gateway' => 'smpp:' . $gatewayId]);
                }
            } else {
                $formats[$gatewayId] ??= (string)(smpp_connector_row($gatewayId)['dlr_id_format'] ?? 'auto');
                $result = smpp_apply_receipt($gatewayId, (string)$event['message_id'], (string)$event['dlr_stat'], $event['done_at'], $formats[$gatewayId]);
                if ($result === 'unmatched' && (int)$event['attempts'] < 20) {
                    $db->prepare('UPDATE ellsms_smpp_events SET processed_at = NULL, result = NULL, attempts = attempts + 1 WHERE id = ?')->execute([$event['id']]);
                    $stats['retry']++;
                    continue;
                }
                $stats[$result]++;
            }
            $db->prepare('UPDATE ellsms_smpp_events SET result = ? WHERE id = ?')->execute([$result, $event['id']]);
            $stats['processed']++;
        } catch (Throwable $t) {
            Logger::error('gateway.smpp.event_failed', ['event_id' => $event['id'], 'exception' => $t]);
            $db->prepare('UPDATE ellsms_smpp_events SET processed_at = NULL, result = NULL, attempts = attempts + 1 WHERE id = ?')->execute([$event['id']]);
        }
    }
    return $stats;
}

/** @return 'updated'|'unchanged'|'unmatched' */
function smpp_apply_receipt(int $gatewayId, string $messageId, string $stat, ?string $doneAt, string $format = 'auto'): string {
    $ids = smpp_message_id_variants($messageId, $format);
    if ($ids === []) return 'unmatched';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = [];
    $st = db()->prepare("SELECT id, delivery_status FROM ellsms_bulk_items WHERE gateway_id = ? AND provider_message_id IN ({$placeholders})");
    $st->execute(array_merge([$gatewayId], $ids));
    foreach ($st->fetchAll() as $r) $rows[] = ['bulk_item', (int)$r['id'], $r['delivery_status']];
    $st = db()->prepare("SELECT id, delivery_status FROM ellsms_message_attempts WHERE gateway_id = ? AND provider_message_id IN ({$placeholders})");
    $st->execute(array_merge([$gatewayId], $ids));
    foreach ($st->fetchAll() as $r) $rows[] = ['attempt', (int)$r['id'], $r['delivery_status']];
    if ($rows === []) return 'unmatched';
    $next = smpp_dlr_canonical($stat);
    $deliveredAt = $next === 'delivered' ? ($doneAt ?: date('Y-m-d H:i:s')) : null;
    $changed = false;
    foreach ($rows as [$source, $rowId, $current]) {
        $changed = gateway_status_record($source, $rowId, $current !== null ? (string)$current : null, $next, $deliveredAt, $stat) || $changed;
    }
    return $changed ? 'updated' : 'unchanged';
}

/* ==========================================================================
   Panel: SMPP settings
   ========================================================================== */

/** Defaults for a new SMPP gateway (also the form's initial values). */
function smpp_connector_defaults(): array {
    return [
        'host' => '', 'port' => 2775, 'use_tls' => 0, 'system_id' => '', 'system_type' => '', 'interface_version' => '3.4',
        'bind_mode' => 'trx', 'session_count' => 1, 'tps' => 50, 'window_size' => 10, 'enquire_link_s' => 30,
        'reconnect_delay_s' => 5, 'submit_timeout_ms' => 10000, 'source_ton' => 5, 'source_npi' => 0, 'dest_ton' => 1,
        'dest_npi' => 1, 'data_coding' => 'auto', 'long_message' => 'udh', 'registered_delivery' => 1,
        'validity_minutes' => 0, 'destination_format' => 'international', 'dlr_id_format' => 'auto', 'receive_enabled' => 1,
    ];
}

/**
 * Validates the SMPP settings form. Returns [errors, row]. Field limits are the SMPP 3.4 ones
 * (system_id ≤ 15, password ≤ 8, system_type ≤ 12 octets) — a longer value is refused by the SMSC
 * (and by jSMPP before it is even sent), so it is caught here instead of as a bind failure later.
 *
 * @return array{0: array<string, string>, 1: array}
 */
function smpp_connector_validate(array $in): array {
    $errors = [];
    $row = smpp_connector_defaults();
    $row['host'] = trim((string)($in['host'] ?? ''));
    if (filter_var($row['host'], FILTER_VALIDATE_IP) === false && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,188})$/', $row['host']) !== 1) {
        $errors['host'] = 'آدرس سرور SMPP باید نام دامنه یا IP باشد.';
    }
    $int = static function (string $key, int $min, int $max) use ($in, &$row, &$errors): void {
        $v = filter_var($in[$key] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) {
            $errors[$key] = "مقدار باید بین {$min} و {$max} باشد.";
            return;
        }
        $row[$key] = $v;
    };
    $int('port', 1, 65535);
    $int('session_count', 1, 10);
    $int('tps', 1, 5000);
    $int('window_size', 1, 500);
    $int('enquire_link_s', 5, 600);
    $int('reconnect_delay_s', 1, 300);
    $int('submit_timeout_ms', 1000, 120000);
    $int('source_ton', 0, 6);
    $int('source_npi', 0, 18);
    $int('dest_ton', 0, 6);
    $int('dest_npi', 0, 18);
    $int('validity_minutes', 0, 10080);
    $row['system_id'] = trim((string)($in['system_id'] ?? ''));
    if ($row['system_id'] === '' || strlen($row['system_id']) > 15) $errors['system_id'] = 'system_id لازم است (حداکثر ۱۵ نویسه).';
    $row['system_type'] = trim((string)($in['system_type'] ?? ''));
    if (strlen($row['system_type']) > 12) $errors['system_type'] = 'system_type حداکثر ۱۲ نویسه است.';
    $enum = static function (string $key, array $allowed) use ($in, &$row): void {
        $v = (string)($in[$key] ?? '');
        if (in_array($v, $allowed, true)) $row[$key] = $v;
    };
    $enum('interface_version', ['3.4', '5.0']);
    $enum('bind_mode', ['trx', 'tx_rx', 'tx']);
    $enum('data_coding', ['auto', 'gsm7', 'ucs2', 'latin1']);
    $enum('long_message', ['udh', 'sar', 'payload']);
    $enum('destination_format', ['international', 'national', 'as_is']);
    $enum('dlr_id_format', ['auto', 'as_is', 'hex_to_dec', 'dec_to_hex']);
    $row['use_tls'] = empty($in['use_tls']) ? 0 : 1;
    $row['registered_delivery'] = empty($in['registered_delivery']) ? 0 : 1;
    $row['receive_enabled'] = empty($in['receive_enabled']) ? 0 : 1;
    $password = (string)($in['password'] ?? '');
    if (strlen($password) > 8) $errors['password'] = 'رمز SMPP حداکثر ۸ نویسه است (محدودیت پروتکل).';
    return [$errors, $row];
}

/** Inserts or updates the SMPP settings row. The password goes to the encrypted vault, not here. */
function smpp_connector_save(int $gatewayId, array $row): void {
    $cols = array_keys(smpp_connector_defaults());
    $values = array_map(static fn(string $c) => $row[$c], $cols);
    $update = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols));
    db()->prepare('INSERT INTO ellsms_sms_gateway_smpp_connectors (gateway_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ") ON DUPLICATE KEY UPDATE {$update}")
        ->execute(array_merge([$gatewayId], $values));
}

/** Session rows the bridge published for one gateway (the monitoring card). */
function smpp_sessions_for_gateway(int $gatewayId): array {
    try {
        $st = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_s FROM ellsms_smpp_sessions WHERE gateway_id = ? ORDER BY session_key');
        $st->execute([$gatewayId]);
        return $st->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

/** Receipt/MO counts for the monitoring card (last 24 hours). */
function smpp_event_stats(int $gatewayId): array {
    try {
        $st = db()->prepare(
            "SELECT
                SUM(event_type = 'dlr') AS dlr,
                SUM(event_type = 'dlr' AND dlr_stat = 'DELIVRD') AS dlr_delivered,
                SUM(event_type = 'dlr' AND dlr_stat IN ('UNDELIV','EXPIRED','REJECTD','DELETED')) AS dlr_failed,
                SUM(event_type = 'dlr' AND result = 'unmatched') AS dlr_unmatched,
                SUM(event_type = 'mo') AS mo,
                SUM(processed_at IS NULL) AS pending
               FROM ellsms_smpp_events WHERE gateway_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $st->execute([$gatewayId]);
        return array_map('intval', $st->fetch() ?: []);
    } catch (PDOException) {
        return [];
    }
}
