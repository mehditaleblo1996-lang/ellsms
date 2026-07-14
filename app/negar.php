<?php
/**
 * ELLSMS — sends SMS by calling the Vesal/Armaghan gateway directly
 * (the same gateway negar-python's common/smsgateway/vesal/tomany_service.py
 * calls) and writing rows straight into negar's `outbound_message` table.
 * This bypasses negar-python's rest_api entirely — no HTTP call to it is
 * made for sending.
 *
 * Vesal contract (POST {VESAL_REST_URL}/OneToMany):
 *   { "username": "...", "password": "...", "originator": 5000435800,
 *     "destinations": ["989..."], "content": "..." }
 *   -> { "references": [123, 124, ...], "error_model": {"error_code": 0} }
 *   error_code < 0 means the whole send failed (see error_code.py on the
 *   Python side for the meaning of each negative code).
 *
 * Delivery reports and inbound (MO) messages are still received by
 * negar-python's own /delivery and /mo endpoints (rest_api/routers/receiver.py)
 * straight into the shared outbound_message/inbound_message tables — ELLSMS
 * only reads them, it does not need its own webhook for those.
 */

require_once __DIR__ . '/bootstrap.php';

/** Low-level call to the Vesal gateway. Returns [ok, httpCode, decoded]. */
function vesal_send(string $originator, array $destinations, string $content): array {
    $base = rtrim((string)setting('vesal_rest_url', env('VESAL_REST_URL', '')), '/');
    if ($base === '') {
        return [false, 0, ['error_model' => ['error_code' => -501], 'error' => 'Vesal REST URL is not configured — set it in Settings.']];
    }
    $url = $base . '/OneToMany';

    $payload = json_encode([
        'username'     => setting('vesal_username', env('VESAL_USERNAME', 'negar')),
        'password'     => setting('vesal_password', env('VESAL_PASSWORD', '')),
        'originator'   => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations' => array_values($destinations),
        'content'      => $content,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [false, 0, ['error_model' => ['error_code' => -501], 'error' => $err ?: 'connection failed']];
    }
    $decoded = json_decode($body, true);
    $errorCode = $decoded['error_model']['error_code'] ?? null;
    $ok = $code >= 200 && $code < 300 && ($errorCode === null || $errorCode === 0);
    return [$ok, $code, $decoded ?? ['raw' => substr($body, 0, 2000)]];
}

/**
 * Send a message batch for a user: credit check, direct Vesal call, one
 * `outbound_message` row per destination (same table negar-python's own
 * /api/messages/send writes to, and the same table its /delivery webhook
 * updates by reference_id — so delivery reports keep working automatically).
 * Returns [ok, infoMessage].
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null): array {
    if (!$destinations)          return [false, 'No valid destination numbers.'];
    if (trim($content) === '')   return [false, 'Message content is empty.'];

    $parts = sms_parts($content);
    $cost  = $parts * count($destinations);

    if ($user['role'] !== 'admin' && (float)$user['credit'] < $cost) {
        return [false, "Not enough credit: this send needs {$cost} credit(s), you have " . (int)$user['credit'] . '.'];
    }

    [$ok, $http, $resp] = vesal_send($originator, $destinations, $content);
    $references = $resp['references'] ?? [];
    $errorCode  = $resp['error_model']['error_code'] ?? ($ok ? 0 : -201);

    $db = db();
    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO outbound_message
               (sender_user_id, originator, destination, content, reference_id, status, error_code, sent_at)
             VALUES (?,?,?,?,?,?,?, NOW())'
        );
        foreach (array_values($destinations) as $i => $dest) {
            $ref = $references[$i] ?? null;
            $ins->execute([
                $user['id'], $originator, $dest, $content,
                $ref, $ok ? 'sent' : 'send_failed', $ok ? null : $errorCode,
            ]);
        }

        if ($ok && $user['role'] !== 'admin') {
            $db->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
               ->execute([$cost, $user['id']]);
        }
        $db->commit();
    } catch (Throwable $t) {
        $db->rollBack();
        return [false, 'Database error while saving the send: ' . $t->getMessage()];
    }

    $reason = $ok ? null : ($http === 0
        ? ($resp['error'] ?? 'Could not reach the Vesal gateway.')
        : "Gateway rejected the send (error code {$errorCode}, HTTP {$http}).");

    return $ok
        ? [true, 'Sent to ' . count($destinations) . " number(s) — {$parts} part(s) each, {$cost} credit(s)."]
        : [false, $reason . ' See the report for details.'];
}

/** Process due scheduled messages. Returns number processed. Used by the worker. */
function run_due_schedules(): int {
    $db = db();
    $due = $db->query("SELECT * FROM ellsms_schedule
                       WHERE status = 'active' AND run_at <= NOW()
                       ORDER BY run_at ASC LIMIT 20")->fetchAll();
    $n = 0;
    foreach ($due as $s) {
        $claim = $db->prepare("UPDATE ellsms_schedule SET status='processing' WHERE id=? AND status='active'");
        $claim->execute([$s['id']]);
        if ($claim->rowCount() === 0) continue;

        $ust = $db->prepare(
            'SELECT u.id, u.active, u.deleted, u.currentcredit AS credit, m.is_admin, m.panel_access
             FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
        );
        $ust->execute([$s['user_id']]);
        $row = $ust->fetch();

        $dests = json_decode($s['destinations'], true) ?: [];
        if ($row && $row['active'] && !$row['deleted']) {
            $user = ['id' => $row['id'], 'role' => $row['is_admin'] ? 'admin' : 'user', 'credit' => $row['credit']];
            [$ok, $info] = dispatch_message($user, $s['originator'], $dests, $s['content'], (int)$s['id']);
        } else {
            [$ok, $info] = [false, 'User account is missing, disabled, or no longer has panel access.'];
        }

        $next = null;
        if ($s['repeat_type'] !== 'none') {
            $iv = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$s['repeat_type']];
            $t  = strtotime($s['run_at']);
            while ($t <= time()) $t = strtotime($iv, $t);
            $next = date('Y-m-d H:i:s', $t);
        }

        $db->prepare('UPDATE ellsms_schedule SET status=?, run_at=COALESCE(?, run_at),
                      last_run_at=NOW(), last_result=?, run_count=run_count+1 WHERE id=?')
           ->execute([$next ? 'active' : 'done', $next, ($ok ? 'OK: ' : 'FAIL: ') . $info, $s['id']]);
        $n++;
    }
    return $n;
}
