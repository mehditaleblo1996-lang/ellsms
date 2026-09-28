<?php
/**
 * #42 — the Bale messenger as a second sending channel.
 *
 * Bale's business API delivers a text to a phone number's Bale account — cheaper than SMS for OTPs
 * and notifications. Request shape (as Vesal's BaleDispatchJob / BaleApi use it):
 *   POST {base_url}/api/v3/send_message
 *   header  api-access-key: <key>
 *   body    {"bot_id": <number>, "phone_number": "98912…", "message_data": {"message": {"text": "…"}}}
 *   answer  {"request_id": "…", "message_id": "…"}
 *
 * Configuration: settings bale_enabled, bale_base_url, bale_bot_id, bale_tps, bale_price_credits (edited on
 * /messages/bale by a platform admin); the access key is the BALE_API_ACCESS_KEY environment variable,
 * never stored in the database.
 *
 * Money and quota mirror dispatch_message(): quota and the worst case (price × recipients) are
 * reserved first, only ACCEPTED messages are committed, the rest is released; a replay of the same
 * reference is recognised and not sent twice. Every recipient is recorded in ellsms_channel_messages.
 *
 * dispatch_with_channel() is the entry point for callers offering a channel choice (the public API's
 * `channel`, the send page): 'sms' (unchanged path), 'bale' (Bale only), 'bale_sms' (Bale first, SMS
 * for whoever Bale did not accept — each channel charged only for what it delivered).
 */

declare(strict_types=1);

const MESSAGE_CHANNELS = ['sms', 'bale', 'bale_sms'];

function bale_setting(string $key, string $default): string {
    return (string)setting_fresh($key, $default);
}

function bale_access_key(): string {
    return trim((string)env('BALE_API_ACCESS_KEY', ''));
}

/** Ready to send: switched on, with a base URL, a numeric bot id and an access key. */
function bale_configured(): bool {
    return bale_setting('bale_enabled', '0') === '1'
        && bale_setting('bale_base_url', '') !== ''
        && ctype_digit(bale_setting('bale_bot_id', ''))
        && bale_access_key() !== '';
}

function bale_price_credits(): int {
    return max(0, (int)bale_setting('bale_price_credits', '1'));
}

/** Blocks just long enough to stay under bale_tps messages per second (per process). */
function bale_rate_limit(): void {
    $tps = max(1, (int)bale_setting('bale_tps', '20'));
    $bucket = &$GLOBALS['__bale_bucket'];
    $now = microtime(true);
    if (!is_array($bucket)) {
        $bucket = ['tokens' => (float)$tps, 'at' => $now];
    }
    $bucket['tokens'] = min((float)$tps, $bucket['tokens'] + ($now - $bucket['at']) * $tps);
    $bucket['at'] = $now;
    if ($bucket['tokens'] < 1.0) {
        usleep((int)ceil((1.0 - $bucket['tokens']) / $tps * 1_000_000));
        $bucket['tokens'] = 1.0;
        $bucket['at'] = microtime(true);
    }
    $bucket['tokens'] -= 1.0;
}

/** One HTTP call. @return array{ok:bool, message_id:?string, error:?string} */
function bale_http_send(string $mobile, string $text): array {
    $url = rtrim(bale_setting('bale_base_url', ''), '/') . '/api/v3/send_message';
    $check = gateway_endpoint_allowed($url);
    if (!$check['ok']) {
        return ['ok' => false, 'message_id' => null, 'error' => $check['reason']];
    }
    $botId = bale_setting('bale_bot_id', '');
    $body = json_encode([
        'bot_id' => strlen($botId) <= 18 ? (int)$botId : $botId,
        'phone_number' => $mobile,
        'message_data' => ['message' => ['text' => $text]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'api-access-key: ' . bale_access_key()],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($check['resolve'] !== []) {
        $options[CURLOPT_RESOLVE] = $check['resolve'];
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'message_id' => null, 'error' => 'connection: ' . mb_strimwidth($curlError, 0, 200, '…')];
    }
    $decoded = json_decode((string)$raw, true);
    $messageId = is_array($decoded) ? gateway_provider_message_id_normalize($decoded['message_id'] ?? null) : null;
    if ($http >= 200 && $http < 300 && $messageId !== null) {
        return ['ok' => true, 'message_id' => $messageId, 'error' => null];
    }
    return ['ok' => false, 'message_id' => null, 'error' => 'http ' . $http . ': ' . mb_strimwidth((string)$raw, 0, 300, '…')];
}

/**
 * Sends $content to every destination through Bale, with quota, billing and records.
 *
 * @return array{ok:bool, info:string, sent:list<string>, failed:array<string,string>, cost:int}
 */
function bale_dispatch(array $user, array $destinations, string $content, string $refType, string $refId): array {
    $destinations = array_values(array_unique(array_map('strval', $destinations)));
    $total = count($destinations);
    $fail = static fn(string $info, string $reason): array => ['ok' => false, 'info' => $info, 'sent' => [], 'failed' => array_fill_keys($destinations, $reason), 'cost' => 0];

    if ($total === 0) return $fail('شماره مقصد معتبری وارد نشده است.', 'no_destination');
    if (!bale_configured()) return $fail('کانال بله پیکربندی نشده است.', 'bale_not_configured');
    if (!impersonation_action_allowed('send.direct')) {
        impersonation_record_block('send.direct');
        return $fail(impersonation_block_message('send.direct'), 'impersonation_blocked');
    }
    if (($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'bale', (int)($user['id'] ?? 0));
        return $fail(CONTENT_POLICY_ERROR, 'content_prohibited');
    }

    $userId = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $organizationId = (int)($user['organization_id'] ?? 0);

    if ($organizationId > 0) {
        $quota = usage_reserve_messages($organizationId, $total, $refType, $refId);
        if (!$quota['ok']) {
            return $fail('سقف ارسال پیام پلن سازمان شما در این دوره تکمیل شده است.', 'quota_exceeded');
        }
    }
    $price = $isAdmin ? 0 : bale_price_credits();
    $worstCase = $price * $total;
    if ($worstCase > 0) {
        $reservation = wallet_reserve($userId, $worstCase, $refType, $refId, "reserve:{$refType}:{$refId}");
        if (!$reservation['ok']) {
            if ($organizationId > 0) usage_release_messages($refType, $refId);
            return $fail('اعتبار کافی نیست: این ارسال به ' . $worstCase . ' واحد اعتبار نیاز دارد.', 'insufficient_credit');
        }
        if (($reservation['status'] ?? 'active') !== 'active') {
            Logger::info('bale.send.already_finalized', ['ref_type' => $refType, 'ref_id' => $refId]);
            return ['ok' => true, 'info' => 'این ارسال قبلاً پردازش شده است.', 'sent' => [], 'failed' => [], 'cost' => 0];
        }
    }

    $sent = [];
    $failed = [];
    $record = db()->prepare(
        "INSERT INTO ellsms_channel_messages
           (channel, organization_id, user_id, reference_type, reference_id, destination, content, status, provider_message_id, error, cost_credits)
         VALUES ('bale',?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($destinations as $destination) {
        bale_rate_limit();
        $result = bale_http_send($destination, $content);
        if ($result['ok']) {
            $sent[] = $destination;
        } else {
            $failed[$destination] = (string)$result['error'];
        }
        $record->execute([
            $organizationId ?: null, $userId, $refType, $refId, $destination, $content,
            $result['ok'] ? 'accepted' : 'failed', $result['message_id'], $result['ok'] ? null : mb_strimwidth((string)$result['error'], 0, 480, '…'),
            $result['ok'] ? $price : 0,
        ]);
    }

    $cost = $price * count($sent);
    if ($worstCase > 0) {
        if ($cost > 0) {
            wallet_commit_reservation($refType, $refId, $cost, "commit:{$refType}:{$refId}", null, ['sent' => count($sent), 'channel' => 'bale']);
        }
        wallet_release_reservation($refType, $refId);
    }
    if ($organizationId > 0) {
        usage_commit_messages($refType, $refId, count($sent));
    }
    Metrics::increment('bale.sent', count($sent));
    if ($failed !== []) Metrics::increment('bale.failed', count($failed));
    Logger::info('bale.send.completed', ['user_id' => $userId, 'sent' => count($sent), 'failed' => count($failed), 'ref_type' => $refType]);

    $info = count($sent) === $total
        ? to_persian_digits((string)count($sent)) . ' پیام از طریق بله ارسال شد.'
        : to_persian_digits((string)count($sent)) . ' از ' . to_persian_digits((string)$total) . ' پیام از طریق بله ارسال شد.';
    return ['ok' => $sent !== [], 'info' => $info, 'sent' => $sent, 'failed' => $failed, 'cost' => $cost];
}

/**
 * dispatch_message()'s tuple — [ok, info, retryable, sentCount, total, parts] — for a chosen channel,
 * plus a 7th element ['bale' => n, 'sms' => n] saying which channel carried how many.
 */
function dispatch_with_channel(array $user, string $originator, array $destinations, string $content, string $channel,
                               ?string $walletRefType = null, ?string $walletRefId = null, ?string $messageType = null): array {
    $channel = in_array($channel, MESSAGE_CHANNELS, true) ? $channel : 'sms';
    $total = count($destinations);
    if ($channel === 'sms' || ($channel === 'bale_sms' && !bale_configured())) {
        $r = dispatch_message($user, $originator, $destinations, $content, null, $walletRefType, $walletRefId, $messageType);
        return array_merge(array_slice($r, 0, 6), [['bale' => 0, 'sms' => (int)$r[3]]]);
    }

    $refId = $walletRefId ?? ('bale-' . bin2hex(random_bytes(12)));
    $bale = bale_dispatch($user, $destinations, $content, 'bale:' . ($walletRefType ?? 'direct_send'), $refId);
    $baleSent = count($bale['sent']);
    if ($channel === 'bale' || $bale['failed'] === []) {
        return [$bale['ok'], $bale['info'], false, $baleSent, $total, 1, ['bale' => $baleSent, 'sms' => 0]];
    }

    // bale_sms: whoever Bale did not take goes out as SMS, charged as SMS.
    $rest = array_values(array_diff(array_map('strval', $destinations), $bale['sent']));
    $sms = dispatch_message($user, $originator, $rest, $content, null, $walletRefType ?? 'direct_send', ($walletRefId ?? $refId) . ':sms', $messageType);
    $smsSent = (int)$sms[3];
    $info = ($baleSent > 0 ? $bale['info'] . ' ' : '') . $sms[1];
    return [$baleSent + $smsSent > 0, $info, $baleSent + $smsSent > 0 ? false : (bool)$sms[2], $baleSent + $smsSent, $total, (int)$sms[5], ['bale' => $baleSent, 'sms' => $smsSent]];
}
