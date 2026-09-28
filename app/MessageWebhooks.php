<?php
/**
 * #39 — per-message webhooks: final delivery state (message.delivered / message.undelivered) and
 * inbound SMS (message.received).
 *
 * Both can be one event per message on a million-row job, so they are emitted ONLY for an
 * organization that has an enabled endpoint subscribed to that event type (checked with a short
 * cache) — webhook_event_emit() would otherwise record an event row even with no subscriber.
 *
 * Exactly once:
 *  - delivery: emitted from gateway_status_record() only when ITS conditional UPDATE moved the row
 *    into a terminal state — the SQL guard lets exactly one worker win that transition;
 *  - received: claimed in ellsms_inbound_webhook_claims before emitting, because the scan that sees
 *    new inbound rows is at-least-once.
 * A webhook failure never affects the status write or the inbound processing that triggered it.
 */

declare(strict_types=1);

/** Whether $organizationId has an ENABLED endpoint subscribed to $eventType. Cached ~30 s per process. */
function webhook_org_subscribes(int $organizationId, string $eventType): bool {
    if ($organizationId <= 0) return false;
    $cache = &$GLOBALS['__webhook_subscriptions'];
    if (!is_array($cache) || microtime(true) - ($cache['at'] ?? 0) > 30) {
        $cache = ['at' => microtime(true), 'values' => []];
    }
    $key = $organizationId . '|' . $eventType;
    if (!array_key_exists($key, $cache['values'])) {
        $st = db()->prepare('SELECT 1 FROM ellsms_webhook_endpoints WHERE organization_id = ? AND enabled = 1 AND JSON_CONTAINS(event_types_json, ?) LIMIT 1');
        $st->execute([$organizationId, json_encode($eventType)]);
        $cache['values'][$key] = $st->fetchColumn() !== false;
    }
    return $cache['values'][$key];
}

function webhook_subscriptions_cache_reset(): void {
    $GLOBALS['__webhook_subscriptions'] = null;
}

/** Called by gateway_status_record() right after it moved a row into terminal state $state. */
function webhook_emit_delivery_state(string $source, int $rowId, string $state, ?string $deliveredAt): void {
    try {
        $eventType = $state === 'delivered' ? WebhookEvents::MESSAGE_DELIVERED : WebhookEvents::MESSAGE_UNDELIVERED;
        if ($source === 'attempt') {
            $st = db()->prepare('SELECT id, organization_id, reference_type, reference_id, destination, originator, provider_message_id
                                 FROM ellsms_message_attempts WHERE id = ?');
            $st->execute([$rowId]);
            $row = $st->fetch();
            if (!$row) return;
            $organizationId = (int)($row['organization_id'] ?? 0);
            $resourceType = 'message_attempt';
            $data = [
                'message_id' => 'attempt:' . $row['id'],
                'reference_type' => (string)$row['reference_type'], 'reference_id' => (string)$row['reference_id'],
                'originator' => (string)($row['originator'] ?? ''), 'destination' => (string)($row['destination'] ?? ''),
            ];
        } else {
            $st = db()->prepare('SELECT bi.id, bi.job_id, bi.mobile, bi.provider_message_id, j.organization_id, j.originator
                                 FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs j ON j.id = bi.job_id WHERE bi.id = ?');
            $st->execute([$rowId]);
            $row = $st->fetch();
            if (!$row) return;
            $organizationId = (int)($row['organization_id'] ?? 0);
            $resourceType = 'bulk_item';
            $data = [
                'message_id' => 'bulk_item:' . $row['id'], 'bulk_job_id' => (string)$row['job_id'],
                'originator' => (string)$row['originator'], 'destination' => (string)$row['mobile'],
            ];
        }
        if (!webhook_org_subscribes($organizationId, $eventType)) return;
        $data += [
            'status' => $state,
            'delivered_at' => $deliveredAt,
            'provider_message_id' => $row['provider_message_id'] !== null ? (string)$row['provider_message_id'] : null,
        ];
        webhook_event_emit($organizationId, $eventType, $resourceType, (string)$rowId, $data);
    } catch (Throwable $t) {
        Logger::warning('webhook.delivery_state_emit_failed', ['source' => $source, 'row_id' => $rowId, 'exception' => $t]);
    }
}

/** The organization a receiving line belongs to: its number record, else the account using it as its line. */
function inbound_line_organization(string $line): int {
    $db = db();
    $st = $db->prepare('SELECT organization_id FROM ellsms_numbers WHERE number = ? AND organization_id IS NOT NULL LIMIT 1');
    $st->execute([$line]);
    $org = (int)($st->fetchColumn() ?: 0);
    if ($org > 0) return $org;
    $st = $db->prepare("SELECT m.organization_id FROM ellsms_meta em
                        JOIN ellsms_organization_memberships m ON m.user_id = em.user_id AND m.status = 'active'
                        WHERE em.originator = ? ORDER BY m.id LIMIT 1");
    $st->execute([$line]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Called for every new inbound message (both stores) by run_autoreply_pass(). */
function webhook_emit_inbound_received(array $msg): void {
    try {
        $line = normalize_originator((string)($msg['destination'] ?? ''));
        $inboundId = (int)($msg['id'] ?? 0);
        if ($line === null || $inboundId <= 0) return;
        $organizationId = inbound_line_organization($line);
        if (!webhook_org_subscribes($organizationId, WebhookEvents::MESSAGE_RECEIVED)) return;
        $claim = db()->prepare('INSERT INTO ellsms_inbound_webhook_claims (inbound_message_id, organization_id) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE inbound_message_id = inbound_message_id');
        $claim->execute([$inboundId, $organizationId]);
        if ($claim->rowCount() !== 1) return;
        webhook_event_emit($organizationId, WebhookEvents::MESSAGE_RECEIVED, 'inbound_message', (string)$inboundId, [
            'message_id'  => 'inbound:' . $inboundId,
            'line'        => $line,
            'from'        => (string)($msg['originator'] ?? ''),
            'content'     => (string)($msg['content'] ?? ''),
            'received_at' => (string)($msg['received_at'] ?? ''),
        ]);
    } catch (Throwable $t) {
        Logger::warning('webhook.inbound_emit_failed', ['inbound_message_id' => $msg['id'] ?? null, 'exception' => $t]);
    }
}
