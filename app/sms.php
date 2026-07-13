<?php
/**
 * ELLSMS — SMS gateway client and dispatch logic.
 *
 * Gateway contract (ravixops REST):
 *   POST {API_BASE}/api/messages/send
 *   { "sender_user_id": 1, "originator": 5000435800,
 *     "destinations": ["9891..."], "content": "..." }
 */

require_once __DIR__ . '/bootstrap.php';

/** Low-level call to the SMS gateway. Returns [ok(bool), httpCode, decoded|raw]. */
function gateway_send(int $senderUserId, string $originator, array $destinations, string $content): array {
    $base = rtrim(setting('api_base_url', env('SMS_API_BASE', 'https://rest.ravixops.com')), '/');
    $url  = $base . '/api/messages/send';

    $payload = json_encode([
        'sender_user_id' => $senderUserId,
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values($destinations),
        'content'        => $content,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [false, 0, ['error' => $err ?: 'connection failed']];
    }
    $decoded = json_decode($body, true);
    $ok = $code >= 200 && $code < 300;
    return [$ok, $code, $decoded ?? ['raw' => substr($body, 0, 2000)]];
}

/**
 * Send a message batch for a user: credit check, gateway call, per-destination
 * rows in `messages`, credit deduction. Returns [ok, infoMessage, batchId].
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null): array {
    if (!$destinations)          return [false, 'No valid destination numbers.', null];
    if (trim($content) === '')   return [false, 'Message content is empty.', null];

    $parts = sms_parts($content);
    $cost  = $parts * count($destinations);

    if ($user['role'] !== 'admin' && (int)$user['credit'] < $cost) {
        return [false, "Not enough credit: this send needs {$cost} credits, you have {$user['credit']}.", null];
    }

    $senderUserId = (int)($user['api_sender_id'] ?: setting('default_sender_id', '1'));

    [$ok, $http, $resp] = gateway_send($senderUserId, $originator, $destinations, $content);

    $db = db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('INSERT INTO batches (user_id, schedule_id, originator, content, parts, destination_count, http_code, api_response)
                            VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            $user['id'], $scheduleId, $originator, $content, $parts,
            count($destinations), $http, json_encode($resp, JSON_UNESCAPED_UNICODE),
        ]);
        $batchId = (int)$db->lastInsertId();

        // Gateway may return per-message ids; try common shapes.
        $ids = [];
        if (is_array($resp)) {
            foreach (['message_ids', 'ids', 'messages', 'data'] as $k) {
                if (isset($resp[$k]) && is_array($resp[$k])) { $ids = array_values($resp[$k]); break; }
            }
        }

        $status = $ok ? 'sent' : 'failed';
        $error  = $ok ? null : json_encode($resp, JSON_UNESCAPED_UNICODE);
        $ins = $db->prepare('INSERT INTO messages (batch_id, user_id, originator, destination, content, parts, status, api_message_id, error, sent_at)
                             VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        foreach (array_values($destinations) as $i => $dest) {
            $apiId = null;
            if (isset($ids[$i])) {
                $apiId = is_array($ids[$i]) ? (string)($ids[$i]['id'] ?? $ids[$i]['message_id'] ?? '') : (string)$ids[$i];
                $apiId = $apiId !== '' ? $apiId : null;
            }
            $ins->execute([$batchId, $user['id'], $originator, $dest, $content, $parts, $status, $apiId, $error]);
        }

        if ($ok && $user['role'] !== 'admin') {
            $db->prepare('UPDATE users SET credit = credit - ? WHERE id = ?')->execute([$cost, $user['id']]);
        }
        $db->commit();
    } catch (Throwable $t) {
        $db->rollBack();
        return [false, 'Database error while saving the send: ' . $t->getMessage(), null];
    }

    return $ok
        ? [true, 'Sent to ' . count($destinations) . " number(s) — {$parts} part(s) each, {$cost} credit(s).", $batchId]
        : [false, "Gateway rejected the send (HTTP {$http}). Details saved to the report.", $batchId];
}

/** Process due scheduled messages. Returns number processed. Used by the worker. */
function run_due_schedules(): int {
    $db = db();
    $due = $db->query("SELECT s.*, u.id uid FROM schedules s
                       JOIN users u ON u.id = s.user_id
                       WHERE s.status = 'active' AND s.run_at <= NOW()
                       ORDER BY s.run_at ASC LIMIT 20")->fetchAll();
    $n = 0;
    foreach ($due as $s) {
        // claim it (avoids double-send if two workers run)
        $claim = $db->prepare("UPDATE schedules SET status='processing' WHERE id=? AND status='active'");
        $claim->execute([$s['id']]);
        if ($claim->rowCount() === 0) continue;

        $ust = $db->prepare('SELECT * FROM users WHERE id=?');
        $ust->execute([$s['user_id']]);
        $user = $ust->fetch();

        $dests = json_decode($s['destinations'], true) ?: [];
        [$ok, $info] = $user && $user['is_active']
            ? dispatch_message($user, $s['originator'], $dests, $s['content'], (int)$s['id'])
            : [false, 'User missing or disabled.'];

        // next occurrence
        $next = null;
        if ($s['repeat_type'] !== 'none') {
            $iv = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$s['repeat_type']];
            $t  = strtotime($s['run_at']);
            while ($t <= time()) $t = strtotime($iv, $t);
            $next = date('Y-m-d H:i:s', $t);
        }

        $db->prepare('UPDATE schedules SET status=?, run_at=COALESCE(?, run_at),
                      last_run_at=NOW(), last_result=?, run_count=run_count+1 WHERE id=?')
           ->execute([$next ? 'active' : 'done', $next, ($ok ? 'OK: ' : 'FAIL: ') . $info, $s['id']]);
        $n++;
    }
    return $n;
}
