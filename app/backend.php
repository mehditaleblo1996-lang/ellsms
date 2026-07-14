<?php
/**
 * ELLSMS — sends SMS by calling the backend platform's REST API
 * (POST {API_BASE_URL}/api/messages/send), the same endpoint the very
 * first curl example for this project used. The API itself performs the
 * gateway call and writes the resulting rows into the shared
 * `outbound_message` table — ELLSMS reads those rows back from the
 * response instead of writing them itself, so there's a single source
 * of truth for what was actually sent.
 *
 * Request:
 *   { "sender_user_id": 1, "originator": 5000435800,
 *     "destinations": ["989..."], "content": "..." }
 * Response: a JSON array, one object per destination —
 *   { "id", "sender_user_id", "originator", "destination", "content",
 *     "reference_id", "status", "error_code", "sent_at",
 *     "delivered_at", "delivery_status_code" }
 *   status is "sent" on success or "send_failed" on failure.
 *
 * If the API itself can't be reached at all (network down, wrong URL),
 * ELLSMS falls back to writing its own "send_failed" rows directly so
 * the attempt is still visible in the report — but on a normal failure
 * response from the API, nothing is double-written.
 *
 * Delivery reports and inbound (MO) messages are received by the
 * backend platform's own /delivery and /mo endpoints straight into the
 * shared outbound_message/inbound_message tables — ELLSMS only reads
 * them, it does not need its own webhook for those.
 */

require_once __DIR__ . '/bootstrap.php';

/** Low-level call to the backend API. Returns [ok, httpCode, decodedBodyOrNull, rawError]. */
function backend_api_send(int $senderUserId, string $originator, array $destinations, string $content): array {
    $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
    if ($base === '') {
        return [false, 0, null, 'The API base URL is not configured — set it in Settings.'];
    }
    $url = $base . '/api/messages/send';

    $payload = json_encode([
        'sender_user_id' => $senderUserId,
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values(array_map('strval', $destinations)),
        'content'        => $content,
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
        return [false, 0, null, $err ?: 'connection failed'];
    }
    $decoded = json_decode($body, true);
    $ok = $code >= 200 && $code < 300 && is_array($decoded);
    return [$ok, $code, $ok ? $decoded : null, $ok ? null : (is_string($body) ? substr($body, 0, 1000) : 'unexpected response')];
}

/**
 * Turn a failed API response into a readable message. FastAPI validation
 * errors (HTTP 422) come back as {"detail": [{"loc": [...], "msg": "..."}]}
 * — this pulls the field name and reason out instead of just showing the
 * HTTP status code, which on its own doesn't say what was actually wrong.
 */
function describe_api_error(int $http, ?string $rawBody): string {
    if ($http === 0) {
        return $rawBody ?: 'Could not reach the API.';
    }
    $decoded = $rawBody ? json_decode($rawBody, true) : null;
    if (is_array($decoded['detail'] ?? null)) {
        $parts = [];
        foreach ($decoded['detail'] as $d) {
            $field = is_array($d['loc'] ?? null) ? end($d['loc']) : null;
            $msg   = $d['msg'] ?? null;
            if ($field && $msg) $parts[] = "{$field}: {$msg}";
        }
        if ($parts) return 'The API rejected the request (HTTP ' . $http . '): ' . implode('; ', $parts) . '.';
    } elseif (is_string($decoded['detail'] ?? null)) {
        return 'The API rejected the request (HTTP ' . $http . '): ' . $decoded['detail'] . '.';
    }
    return "The API returned HTTP {$http}" . ($rawBody ? ': ' . mb_strimwidth($rawBody, 0, 200, '…') : '.') . '.';
}

/**
 * Send a message batch for a user: credit check, API call, and (only if
 * the API itself was unreachable) a fallback row per destination.
 * Returns [ok, infoMessage].
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null): array {
    if (!$destinations)          return [false, 'No valid destination numbers.'];
    if (trim($content) === '')   return [false, 'Message content is empty.'];

    $originator = normalize_originator($originator);
    if ($originator === null) return [false, 'Sender line (originator) is missing or not numeric — set it above or in Settings.'];

    $parts = sms_parts($content);
    $cost  = $parts * count($destinations);

    if ($user['role'] !== 'admin' && (float)$user['credit'] < $cost) {
        return [false, "Not enough credit: this send needs {$cost} credit(s), you have " . (int)$user['credit'] . '.'];
    }

    [$reached, $http, $rows, $err] = backend_api_send((int)$user['id'], $originator, $destinations, $content);

    if (!$reached) {
        // Couldn't get a usable success response — write our own failed
        // rows so the attempt is still visible in the report, and surface
        // the real reason instead of a generic HTTP-code message.
        $db = db();
        $ins = $db->prepare(
            'INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, error_code, sent_at)
             VALUES (?,?,?,?,?,?, NOW())'
        );
        foreach ($destinations as $dest) {
            $ins->execute([$user['id'], $originator, $dest, $content, 'send_failed', -501]);
        }
        return [false, describe_api_error($http, $err) . ' See the report for details.'];
    }

    $sentCount = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'sent') $sentCount++;
    }
    $allOk = $sentCount === count($destinations);

    if ($allOk && $user['role'] !== 'admin') {
        db()->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
           ->execute([$cost, $user['id']]);
    } elseif ($sentCount > 0 && $user['role'] !== 'admin') {
        // Partial success — only charge for the parts that actually sent.
        db()->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
           ->execute([$parts * $sentCount, $user['id']]);
    }

    if ($allOk) {
        return [true, 'Sent to ' . count($destinations) . " number(s) — {$parts} part(s) each, " . ($parts * $sentCount) . ' credit(s).'];
    }
    if ($sentCount > 0) {
        return [true, "Sent to {$sentCount} of " . count($destinations) . ' number(s) — see the report for which ones failed.'];
    }
    return [false, 'The gateway rejected every destination. See the report for details.'];
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
