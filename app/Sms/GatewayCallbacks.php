<?php
/**
 * Provider PUSH callbacks: inbound SMS (MO) and delivery reports (DLR) that a provider calls on us,
 * as opposed to the receive/status connectors that poll the provider (GatewayReceive.php,
 * GatewayStatus.php).
 *
 *   GET|POST /mo?from=989197684063&to=5000435800&content=سلام 😀
 *   GET|POST /delivery?id=<provider message id>&status=DELIVRD&dl_time=...
 *
 * The same URL shape the backend platform's own /mo and /delivery took, so a provider panel only has
 * to point at a new host. Parameters may arrive as a query string, a form body or a JSON body, under
 * any of the common names (sms_callback_param()).
 *
 * WRITES THROUGH THE EXISTING PATHS. An MO lands in `ellsms_inbound_messages` through
 * gateway_receive_store(), so the inbox, auto-reply, webhooks and number forwarding pick it up exactly
 * as they do a polled one, and a provider re-calling with the same message is dropped by the same
 * dedupe key. A DLR goes through gateway_status_record(), so it inherits the monotonic-state guarantee
 * (a late "sent" can never overwrite "delivered") and fires the delivery webhook/forward once.
 *
 * PERSIAN AND EMOJI. The table and the connection are utf8mb4, so the only work is turning whatever
 * the provider sent into valid UTF-8 (sms_callback_decode_text()): plain percent-encoded UTF-8 (the
 * normal case), JavaScript-style %uXXXX escapes (emoji as surrogate pairs), UCS-2 hex when the
 * provider says so with coding=8/ucs2, and Windows-1256 from older Iranian gateways.
 *
 * AUTHENTICATION. A provider usually can only be given a URL, so the guard is optional and
 * URL-friendly: SMS_CALLBACK_TOKEN (sent as ?token=… or an X-Callback-Token header) and/or
 * SMS_CALLBACK_ALLOWED_IPS (comma-separated IPs/CIDRs). With neither set the endpoints are open, as
 * the backend platform's were — set at least one in production.
 */

declare(strict_types=1);

const SMS_CALLBACK_FROM_KEYS    = ['from', 'originator', 'sender', 'src', 'source', 'source_addr', 'mobile', 'msisdn'];
const SMS_CALLBACK_TO_KEYS      = ['to', 'destination', 'receiver', 'dst', 'dest', 'destination_addr', 'line', 'number'];
const SMS_CALLBACK_CONTENT_KEYS = ['content', 'text', 'message', 'body', 'msg', 'sms', 'short_message'];
const SMS_CALLBACK_CODING_KEYS  = ['coding', 'encoding', 'charset', 'dcs', 'data_coding'];
const SMS_CALLBACK_MO_ID_KEYS   = ['id', 'msgid', 'msg_id', 'message_id', 'messageid', 'smsid', 'sms_id'];
const SMS_CALLBACK_MO_TIME_KEYS = ['time', 'date', 'received_at', 'receive_time', 'datetime', 'timestamp'];
const SMS_CALLBACK_DLR_ID_KEYS  = ['id', 'msgid', 'msg_id', 'message_id', 'messageid', 'smsid', 'sms_id', 'refid', 'ref_id', 'recid', 'provider_message_id'];
const SMS_CALLBACK_DLR_STATUS_KEYS = ['status', 'state', 'stat', 'dlr', 'delivery_status', 'dlr_status'];
const SMS_CALLBACK_DLR_TIME_KEYS   = ['dl_time', 'done_date', 'delivered_at', 'delivery_time', 'time', 'date'];

/**
 * Provider tokens understood without any gateway configuration: SMPP's stat values and plain words.
 * A gateway's own status mapping (Platform Admin → درگاه‌ها → وضعیت) wins over this, which is where
 * numeric provider codes belong — "1" means delivered to one provider and failed to another.
 */
const SMS_CALLBACK_DEFAULT_STATUSES = [
    'delivrd' => 'delivered', 'delivered' => 'delivered', 'deliver' => 'delivered', 'success' => 'delivered',
    'undeliv' => 'failed', 'undelivered' => 'failed', 'undeliverable' => 'failed', 'failed' => 'failed', 'fail' => 'failed',
    'rejectd' => 'rejected', 'rejected' => 'rejected', 'reject' => 'rejected', 'blacklisted' => 'rejected',
    'expired' => 'expired', 'expire' => 'expired',
    'enroute' => 'sent', 'sent' => 'sent', 'submitted' => 'sent',
    'acceptd' => 'accepted', 'accepted' => 'accepted',
    'queued' => 'queued', 'pending' => 'queued',
    'unknown' => 'unknown',
];

/**
 * The request's parameters: query string, then form body, then a JSON object body; keys lowercased.
 * The query string is parsed here from QUERY_STRING rather than taken from $_GET so a content value
 * containing raw %uXXXX escapes survives untouched for sms_callback_decode_text().
 */
function sms_callback_params(?string $queryString = null, ?array $post = null, ?string $rawBody = null): array {
    $params = [];
    parse_str($queryString ?? (string)($_SERVER['QUERY_STRING'] ?? ''), $query);
    $sources = [$query, $post ?? $_POST];
    $rawBody ??= (string)file_get_contents('php://input');
    if (str_starts_with(ltrim($rawBody), '{')) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $sources[] = $json;
        }
    }
    foreach ($sources as $source) {
        foreach ($source as $key => $value) {
            if (is_scalar($value)) {
                $params[strtolower((string)$key)] = (string)$value;
            }
        }
    }
    return $params;
}

/** The first non-empty value under any of $names. */
function sms_callback_param(array $params, array $names): ?string {
    foreach ($names as $name) {
        if (isset($params[$name]) && trim($params[$name]) !== '') {
            return $params[$name];
        }
    }
    return null;
}

/**
 * Message text as valid UTF-8, whatever encoding the provider used (see the file docblock).
 * Never throws and never returns invalid UTF-8: a byte sequence that is none of the known encodings
 * is scrubbed rather than refused, because a message with one bad character is still a message.
 */
function sms_callback_decode_text(string $raw, ?string $coding = null): string {
    $coding = strtolower(trim((string)$coding));
    $text = $raw;

    // UCS-2 / UTF-16BE as hex, only when the provider says so — "1234" must stay "1234".
    if (in_array($coding, ['8', '08', 'ucs2', 'ucs-2', 'utf16', 'utf-16', 'utf-16be', 'unicode', 'hex'], true)
        && strlen($text) % 4 === 0 && preg_match('/^[0-9a-f]+$/iD', $text) === 1) {
        $text = (string)mb_convert_encoding((string)hex2bin($text), 'UTF-8', 'UTF-16BE');
    }

    // JavaScript escape() style: %u0633 and, for emoji, a surrogate pair %uD83D%uDE00.
    $text = (string)preg_replace_callback('/(?:%u[0-9a-f]{4})+/i', static function (array $m): string {
        $hex = str_ireplace('%u', '', $m[0]);
        return (string)mb_convert_encoding((string)hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }, $text);

    if (!mb_check_encoding($text, 'UTF-8')) {
        // Older Iranian gateways send Persian as Windows-1256.
        $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $text) : false;
        $text = is_string($converted) && $converted !== '' ? $converted : mb_scrub($text, 'UTF-8');
    }
    $text = str_replace("\0", '', $text);
    return mb_substr($text, 0, 5000, 'UTF-8');
}

/** Null when the request may proceed, else the reason it is refused. */
function sms_callback_authorize(array $params, string $ip, ?string $headerToken = null): ?string {
    $token = (string)(env('SMS_CALLBACK_TOKEN', '') ?? '');
    if ($token !== '') {
        $given = (string)($headerToken ?? $params['token'] ?? $params['key'] ?? '');
        if ($given === '' || !hash_equals($token, $given)) {
            return 'bad_token';
        }
    }
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string)(env('SMS_CALLBACK_ALLOWED_IPS', '') ?? '')))));
    if ($allowed !== []) {
        foreach ($allowed as $entry) {
            if (ip_in_cidr($ip, $entry)) {
                return null;
            }
        }
        return 'ip_not_allowed';
    }
    return null;
}

/**
 * The gateway a callback belongs to: an explicit ?gateway=<code> (or numeric id), else the gateway
 * the line's route sends through — the same rule gateway_receive_lines() uses — else the default.
 * 0 = none configured.
 */
function sms_callback_resolve_gateway(?string $gateway, string $line): int {
    $gateway = trim((string)$gateway);
    if ($gateway !== '') {
        $st = db()->prepare("SELECT id FROM ellsms_sms_gateways WHERE (code = ? OR id = ?) AND status = 'active' LIMIT 1");
        $st->execute([$gateway, ctype_digit($gateway) ? (int)$gateway : 0]);
        return (int)($st->fetchColumn() ?: 0);
    }
    if ($line !== '') {
        $route = sms_pricing_route_for_sender($line, sms_pricing_normalize_message_type(null));
        if ($route !== null && (int)($route['gateway_id'] ?? 0) > 0) {
            return (int)$route['gateway_id'];
        }
    }
    return gateway_default_id();
}

/**
 * The line as it is registered in ellsms_numbers. Providers do not agree on the format of the
 * receiving line (5000435800, 985000435800, 00985000435800), and the inbox, per-line forwarding and
 * auto-reply all match the stored destination EXACTLY against the assigned number, so a line pushed
 * in another format would reach none of them. Unregistered = returned unchanged.
 */
function sms_callback_canonical_line(string $line): string {
    $candidates = [$line];
    foreach (['0098', '98', '0'] as $prefix) {
        if (str_starts_with($line, $prefix) && strlen($line) - strlen($prefix) >= 3) {
            $candidates[] = substr($line, strlen($prefix));
        }
    }
    $candidates[] = '98' . $line;
    $candidates[] = '0' . $line;
    try {
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $st = db()->prepare("SELECT number FROM ellsms_numbers WHERE number IN ({$placeholders})");
        $st->execute($candidates);
        $found = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException) {
        return $line;
    }
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $found, true)) {
            return $candidate;
        }
    }
    return $line;
}

/**
 * Stores one pushed MO.
 *
 * @return array{http:int, body:array}
 */
function sms_callback_receive_mo(array $params, ?int $now = null): array {
    $now ??= time();
    $rawFrom = trim((string)sms_callback_param($params, SMS_CALLBACK_FROM_KEYS));
    $from = normalize_msisdn($rawFrom) ?? (normalize_originator($rawFrom) ?? '');
    $to = normalize_originator((string)sms_callback_param($params, SMS_CALLBACK_TO_KEYS)) ?? '';
    if ($from === '' || $to === '' || strlen($from) > 20 || strlen($to) > 20) {
        return ['http' => 400, 'body' => ['ok' => false, 'error' => 'from_and_to_required']];
    }
    $to = sms_callback_canonical_line($to);
    $content = sms_callback_decode_text(
        (string)(sms_callback_param($params, SMS_CALLBACK_CONTENT_KEYS) ?? ''),
        sms_callback_param($params, SMS_CALLBACK_CODING_KEYS)
    );

    $gatewayId = sms_callback_resolve_gateway($params['gateway'] ?? null, $to);
    if ($gatewayId <= 0) {
        Logger::error('sms_callback.mo.no_gateway', ['destination' => $to]);
        return ['http' => 503, 'body' => ['ok' => false, 'error' => 'no_gateway_configured']];
    }
    $time = sms_callback_param($params, SMS_CALLBACK_MO_TIME_KEYS);
    $message = [
        'originator'          => $from,
        'destination'         => $to,
        'content'             => $content,
        'received_at'         => gateway_receive_time($time, $now),
        'provider_message_id' => gateway_provider_message_id_normalize(sms_callback_param($params, SMS_CALLBACK_MO_ID_KEYS)),
    ];
    $id = gateway_receive_store(db(), $gatewayId, $message);
    if ($id !== null) {
        Metrics::increment('gateway.inbound.stored', 1, ['gateway' => 'callback']);
    }
    Logger::info('sms_callback.mo.received', ['gateway_id' => $gatewayId, 'destination' => $to, 'duplicate' => $id === null]);
    return ['http' => 200, 'body' => ['ok' => true, 'id' => $id, 'duplicate' => $id === null]];
}

/** Canonical state for a pushed provider token: the gateway's own mapping first, then the defaults. */
function sms_callback_status_canonical(?array $connector, string $token): string {
    $map = is_array($connector['status']['statuses'] ?? null) ? $connector['status']['statuses'] : [];
    $key = mb_strtolower(trim($token));
    return $map[$key] ?? SMS_CALLBACK_DEFAULT_STATUSES[$key] ?? 'unknown';
}

/** A provider delivery time as UTC 'Y-m-d H:i:s' (what delivered_at holds); zone-less = Tehran time. */
function sms_callback_delivered_at(?string $raw, int $now): string {
    $local = gateway_receive_time($raw, $now);
    return (new DateTimeImmutable($local, new DateTimeZone(GATEWAY_RECEIVE_TZ)))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Applies one pushed delivery report to the message it names.
 *
 * Correlates by provider message id across both send records (bulk items, direct-send attempts) —
 * restricted to the gateway when ?gateway= names one. An id that matches messages on two different
 * gateways without that hint is refused: a provider id only means something to the provider that
 * issued it, and guessing would write one customer's status onto another's message.
 *
 * @return array{http:int, body:array}
 */
function sms_callback_receive_dlr(array $params, ?int $now = null): array {
    $now ??= time();
    $providerId = gateway_provider_message_id_normalize(sms_callback_param($params, SMS_CALLBACK_DLR_ID_KEYS));
    $token = trim((string)sms_callback_param($params, SMS_CALLBACK_DLR_STATUS_KEYS));
    if ($providerId === null || $token === '') {
        return ['http' => 400, 'body' => ['ok' => false, 'error' => 'id_and_status_required']];
    }

    $gatewayFilter = 0;
    if (trim((string)($params['gateway'] ?? '')) !== '') {
        $gatewayFilter = sms_callback_resolve_gateway($params['gateway'], '');
        if ($gatewayFilter <= 0) {
            return ['http' => 404, 'body' => ['ok' => false, 'error' => 'unknown_gateway']];
        }
    }
    $where = $gatewayFilter > 0 ? ' AND gateway_id = ' . $gatewayFilter : '';
    $st = db()->prepare(
        "SELECT 'bulk_item' AS source, id, gateway_id, delivery_status FROM ellsms_bulk_items
           WHERE provider_message_id = ? AND gateway_id IS NOT NULL{$where}
         UNION ALL
         SELECT 'attempt' AS source, id, gateway_id, delivery_status FROM ellsms_message_attempts
           WHERE provider_message_id = ? AND gateway_id IS NOT NULL{$where}
         LIMIT 20"
    );
    $st->execute([$providerId, $providerId]);
    $rows = $st->fetchAll();
    if ($rows === []) {
        Logger::warning('sms_callback.dlr.unmatched', ['gateway_id' => $gatewayFilter ?: null]);
        return ['http' => 404, 'body' => ['ok' => false, 'error' => 'message_not_found']];
    }
    if (count(array_unique(array_map(static fn(array $r): int => (int)$r['gateway_id'], $rows))) > 1) {
        Logger::warning('sms_callback.dlr.ambiguous', ['rows' => count($rows)]);
        return ['http' => 409, 'body' => ['ok' => false, 'error' => 'ambiguous_id_pass_gateway']];
    }

    $connector = gateway_compiled((int)$rows[0]['gateway_id']);
    $canonical = sms_callback_status_canonical($connector, $token);
    $deliveredAt = $canonical === 'delivered'
        ? sms_callback_delivered_at(sms_callback_param($params, SMS_CALLBACK_DLR_TIME_KEYS), $now)
        : null;
    $updated = 0;
    foreach ($rows as $row) {
        if (gateway_status_record((string)$row['source'], (int)$row['id'], $row['delivery_status'], $canonical, $deliveredAt, $token)) {
            $updated++;
        }
    }
    Logger::info('sms_callback.dlr.received', ['gateway_id' => (int)$rows[0]['gateway_id'], 'state' => $canonical, 'updated' => $updated]);
    return ['http' => 200, 'body' => ['ok' => true, 'status' => $canonical, 'updated' => $updated]];
}

/** Shared entrypoint body for public/mo.php and public/delivery.php. */
function sms_callback_handle(string $kind): never {
    header('Content-Type: application/json; charset=utf-8');
    $params = sms_callback_params();
    $refused = sms_callback_authorize($params, client_ip(), $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? null);
    if ($refused !== null) {
        Logger::warning('sms_callback.' . $kind . '.refused', ['reason' => $refused, 'ip' => client_ip()]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $refused]);
        exit;
    }
    try {
        $result = $kind === 'mo' ? sms_callback_receive_mo($params) : sms_callback_receive_dlr($params);
    } catch (Throwable $t) {
        Logger::error('sms_callback.' . $kind . '.failed', ['exception' => $t]);
        $result = ['http' => 500, 'body' => ['ok' => false, 'error' => 'internal_error']];
    }
    http_response_code($result['http']);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
    exit;
}
