<?php
/**
 * #37 — inbound SMS (MO) through the gateway's RECEIVE connector.
 *
 * With SMS_GATEWAY_TRANSPORT=1 nothing fills the legacy backend's `inbound_message`, so without this
 * the inbox is empty and the auto-responder never runs. A receive connector describes the provider's
 * "pull received messages" call (e.g. Vesal's POST /pullReceivedMessages); this pass calls it on a
 * schedule and stores what comes back in `ellsms_inbound_messages`, which every inbound consumer
 * already reads alongside `inbound_message` (app/Backend/messages.php).
 *
 * At-least-once, never lost: every poll re-reads a LOOKBACK window (the connector's lookback_seconds),
 * so a message the provider marked as read during a poll that then failed on our side is fetched
 * again next time; the (gateway_id, dedupe_key) unique key drops the repeat. dedupe_key is the
 * provider's id when the answer carries one, else a fingerprint of (sender, line, text, time) — Vesal's
 * answer has no id.
 *
 * One poll per gateway per poll_interval_seconds, claimed with one conditional UPDATE, so several
 * status-worker processes never hammer the provider together.
 */

declare(strict_types=1);

const GATEWAY_RECEIVE_TZ = 'Asia/Tehran';

/** Every sender line whose default route resolves to $gatewayId (the lines this gateway receives for). */
function gateway_receive_lines(int $gatewayId): array {
    $db = db();
    $candidates = [];
    foreach ([
        'SELECT number FROM ellsms_numbers',
        "SELECT sender FROM ellsms_sender_routes WHERE status = 'active'",
        "SELECT originator FROM ellsms_meta WHERE originator <> ''",
    ] as $sql) {
        try {
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $line = normalize_originator((string)$value);
                if ($line !== null && $line !== '') {
                    $candidates[$line] = true;
                }
            }
        } catch (PDOException) {
            // A table this install does not have yet contributes no lines.
        }
    }
    $lines = [];
    foreach (array_keys($candidates) as $line) {
        $line = (string)$line;
        $route = sms_pricing_route_for_sender($line, sms_pricing_normalize_message_type(null));
        $routeGateway = $route !== null && (int)($route['gateway_id'] ?? 0) > 0 ? (int)$route['gateway_id'] : gateway_default_id();
        if ($routeGateway === $gatewayId) {
            $lines[] = $line;
        }
    }
    sort($lines);
    return $lines;
}

/** Takes this gateway's poll slot, or false when another process polled it within the interval. */
function gateway_receive_claim(PDO $db, int $gatewayId, int $intervalSeconds): bool {
    $st = $db->prepare(
        'UPDATE ellsms_sms_gateway_receive_connectors SET last_polled_at = CURRENT_TIMESTAMP
         WHERE gateway_id = ? AND enabled = 1
           AND (last_polled_at IS NULL OR last_polled_at <= CURRENT_TIMESTAMP - INTERVAL ? SECOND)'
    );
    $st->execute([$gatewayId, max(1, $intervalSeconds)]);
    return $st->rowCount() === 1;
}

/** Request variables for one receive call. */
function gateway_receive_context(array $connector, string $line, int $now): array {
    $tz = new DateTimeZone(GATEWAY_RECEIVE_TZ);
    $from = $now - (int)$connector['receive']['lookback_seconds'];
    return [
        'line'         => $line,
        'from_date'    => (new DateTimeImmutable('@' . $from))->setTimezone($tz)->format('Y-m-d\TH:i:s'),
        'to_date'      => (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d\TH:i:s'),
        'from_unix'    => (string)$from,
        'to_unix'      => (string)$now,
        'request_id'   => Logger::currentRequestId(),
        'gateway_code' => (string)$connector['gateway_code'],
        'timestamp'    => (string)$now,
    ];
}

/**
 * A provider time as Tehran wall-clock 'Y-m-d H:i:s' (the zone `inbound_message.received_at` and the
 * inbox's day filters use). Epoch seconds or milliseconds, or a date string without a zone (read as
 * Tehran time, as Vesal sends it). Unparseable = now.
 */
function gateway_receive_time(mixed $raw, int $now): string {
    $tz = new DateTimeZone(GATEWAY_RECEIVE_TZ);
    $ts = null;
    if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
        $n = (int)$raw;
        $ts = $n > 100000000000 ? intdiv($n, 1000) : $n;
    } elseif (is_string($raw) && trim($raw) !== '') {
        try {
            $ts = (new DateTimeImmutable(trim($raw), $tz))->getTimestamp();
        } catch (Exception) {
            $ts = null;
        }
    }
    if ($ts === null || $ts <= 0) {
        $ts = $now;
    }
    return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
}

/**
 * Reads the message rows out of a decoded answer and normalizes them.
 *
 * @return list<array{originator:string, destination:string, content:string, received_at:string, provider_message_id:?string}>
 */
function gateway_receive_extract(array $mapping, mixed $decoded, string $requestedLine, int $now): array {
    $rows = $mapping['rows_path'] === [] ? $decoded : gateway_path_extract($mapping['rows_path'], $decoded);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawSender = trim((string)($row[$mapping['sender_key']] ?? ''));
        $sender = normalize_msisdn($rawSender) ?? $rawSender;
        $rawLine = trim((string)($row[$mapping['line_key']] ?? ''));
        $line = normalize_originator($rawLine !== '' ? $rawLine : $requestedLine) ?? '';
        if ($sender === '' || $line === '' || strlen($sender) > 20 || strlen($line) > 20) {
            continue;
        }
        $content = $row[$mapping['content_key']] ?? '';
        $content = is_scalar($content) ? mb_substr((string)$content, 0, 5000, 'UTF-8') : '';
        $providerId = $mapping['id_key'] !== '' ? gateway_provider_message_id_normalize($row[$mapping['id_key']] ?? null) : null;
        $out[] = [
            'originator'          => $sender,
            'destination'         => $line,
            'content'             => $content,
            'received_at'         => gateway_receive_time($row[$mapping['received_at_key']] ?? null, $now),
            'provider_message_id' => $providerId,
        ];
    }
    return $out;
}

/** Stores one message; returns its new id, or null when it was already stored. */
function gateway_receive_store(PDO $db, int $gatewayId, array $message): ?int {
    $dedupeKey = $message['provider_message_id'] !== null
        ? hash('sha256', 'id:' . $message['provider_message_id'])
        : hash('sha256', implode("\x1f", [$message['originator'], $message['destination'], $message['content'], $message['received_at']]));
    $st = $db->prepare(
        'INSERT INTO ellsms_inbound_messages
           (gateway_id, originator, destination, content, received_at, provider_message_id, dedupe_key)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $st->execute([$gatewayId, $message['originator'], $message['destination'], $message['content'],
                  $message['received_at'], $message['provider_message_id'], $dedupeKey]);
    return $st->rowCount() === 1 ? (int)$db->lastInsertId() : null;
}

/**
 * Polls one gateway (its receive slot must already be claimed).
 *
 * @return array{requests:int, received:int, stored:int, errors:int}
 */
function gateway_receive_poll_gateway(array $connector, ?int $now = null): array {
    $now ??= time();
    $db = db();
    $gatewayId = (int)$connector['gateway_id'];
    $stats = ['requests' => 0, 'received' => 0, 'stored' => 0, 'errors' => 0];
    $lines = $connector['receive']['per_line'] ? gateway_receive_lines($gatewayId) : [''];
    $lastError = null;

    foreach ($lines as $line) {
        $context = gateway_receive_context($connector, $line, $now);
        $request = gateway_build_request($connector, 'receive', $context, null, null);
        $response = gateway_execute($connector, 'receive', $request);
        $stats['requests']++;
        if (!$response['ok']) {
            $stats['errors']++;
            $lastError = mb_strimwidth((string)($response['error'] ?? 'receive request failed'), 0, 480, '…');
            continue;
        }
        foreach (gateway_receive_extract($connector['receive']['mapping'], $response['data'], $line, $now) as $message) {
            $stats['received']++;
            $newId = gateway_receive_store($db, $gatewayId, $message);
            if ($newId !== null) {
                $stats['stored']++;
                Metrics::increment('gateway.inbound.stored', 1, ['gateway' => $connector['gateway_code']]);
            }
        }
    }

    $db->prepare(
        'UPDATE ellsms_sms_gateway_receive_connectors
         SET last_success_at = IF(? = 0, CURRENT_TIMESTAMP, last_success_at), last_error = ?
         WHERE gateway_id = ?'
    )->execute([$stats['errors'], $lastError, $gatewayId]);

    if ($stats['errors'] > 0) {
        Logger::warning('gateway.receive.poll_errors', ['gateway_id' => $gatewayId] + $stats + ['last_error' => $lastError]);
    }
    return $stats;
}

/**
 * One pass over every gateway with an enabled receive connector whose interval has elapsed.
 *
 * @return array{gateways:int, requests:int, received:int, stored:int, errors:int}
 */
function gateway_receive_poll_pass(): array {
    $totals = ['gateways' => 0, 'requests' => 0, 'received' => 0, 'stored' => 0, 'errors' => 0];
    $db = db();
    try {
        $ids = $db->query(
            "SELECT rc.gateway_id FROM ellsms_sms_gateway_receive_connectors rc
             JOIN ellsms_sms_gateways g ON g.id = rc.gateway_id AND g.status = 'active'
             WHERE rc.enabled = 1 ORDER BY rc.gateway_id"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return $totals; // 2026_09_29_gateway_receive.sql not applied yet
    }
    foreach ($ids as $gatewayId) {
        $connector = gateway_compiled((int)$gatewayId);
        if ($connector === null || empty($connector['receive_enabled']) || $connector['receive'] === null) {
            continue;
        }
        if ($connector['is_mock'] && !gateway_mock_enabled()) {
            continue;
        }
        if (!gateway_receive_claim($db, (int)$gatewayId, (int)$connector['receive']['poll_interval_seconds'])) {
            continue;
        }
        try {
            $stats = gateway_receive_poll_gateway($connector);
        } catch (Throwable $t) {
            Logger::error('gateway.receive.poll_failed', ['gateway_id' => (int)$gatewayId, 'exception' => $t]);
            $stats = ['requests' => 0, 'received' => 0, 'stored' => 0, 'errors' => 1];
        }
        $totals['gateways']++;
        foreach (['requests', 'received', 'stored', 'errors'] as $k) {
            $totals[$k] += $stats[$k];
        }
    }
    return $totals;
}
