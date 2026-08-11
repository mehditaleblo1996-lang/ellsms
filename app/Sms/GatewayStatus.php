<?php
/**
 * ELLSMS — delivery-status polling against a gateway's status connector
 * (docs/sms-gateway-connectors.md §Delivery status).
 *
 * WHY POLLING AND NOT A WEBHOOK. A provider callback would need a public, authenticated endpoint per
 * gateway and a story for replay, ordering and spoofing. Polling needs none of that, and the delivery
 * state of an SMS is not latency-critical. A webhook receiver can be added later without changing
 * anything here, because both would write through gateway_status_record() and inherit its
 * monotonicity guarantee.
 *
 * CLAIMING WITHOUT A LEASE COLUMN. `delivery_checked_at` doubles as the lease: a row is claimable
 * only when it has not been checked within the poll interval, and the claim is a compare-and-swap on
 * that same condition. Two workers racing therefore cannot both poll one row — the loser's UPDATE
 * matches zero rows — which is Phase 4's Invariant E without adding columns that would only ever hold
 * a timestamp already present.
 *
 * MONOTONIC BY CONSTRUCTION. A terminal state is never overwritten (gateway_status_may_transition()).
 * Providers do re-report, and out-of-order polls are normal; a `delivered` row silently reverting to
 * `sent` would make the delivery report untrustworthy in a way no operator could detect.
 */

declare(strict_types=1);

/** How long after a send the first status poll is worthwhile, when the connector states no preference. */
const GATEWAY_STATUS_DEFAULT_DELAY = 30;

/** Maximum rows one polling pass will claim. Bounded so one pass cannot monopolise a worker tick. */
function gateway_status_batch_size(): int {
    return max(1, (int)(env('SMS_GATEWAY_STATUS_BATCH', '100') ?? '100'));
}

/**
 * One polling pass: claims due rows, groups them by gateway, and asks each gateway about its own.
 *
 * Grouping by gateway is what keeps the compiled-connector cache effective — a pass over 100 rows
 * spanning three gateways compiles three connectors, not one hundred.
 *
 * @return array{claimed:int, polled:int, updated:int, terminal:int, skipped:int}
 */
function gateway_status_poll_pass(): array {
    $stats = ['claimed' => 0, 'polled' => 0, 'updated' => 0, 'terminal' => 0, 'skipped' => 0];
    $db = db();

    // Two sources, one poller. Bulk items carry their provider id on their own row; direct sends and
    // schedules carry theirs on an ellsms_message_attempts row (docs/sms-gateway-connectors.md
    // §Delivery status). Both are polled by the SAME code so a direct send and a bulk send cannot
    // drift into two different delivery-tracking behaviours.
    //
    // Only rows that were actually sent through a gateway, still carry a provider id, and are not yet
    // terminal. A row with no provider_message_id can never be asked about, so it is excluded in SQL
    // rather than filtered in PHP — otherwise the batch limit would fill up with unaskable rows.
    $limit = gateway_status_batch_size();
    $rows = [];

    $due = $db->prepare(
        "SELECT 'bulk_item' AS source, bi.id, bi.gateway_id, bi.provider_message_id, bi.mobile AS destination,
                bi.delivery_status, bi.delivery_attempts, bi.created_at, bi.delivery_checked_at,
                bj.originator AS sender
         FROM ellsms_bulk_items bi
         JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
         WHERE bi.status = 'sent'
           AND bi.gateway_id IS NOT NULL
           AND bi.provider_message_id IS NOT NULL
           AND (bi.delivery_status IS NULL OR bi.delivery_status NOT IN ('delivered','failed','rejected','expired'))
         ORDER BY bi.delivery_checked_at IS NULL DESC, bi.id ASC
         LIMIT " . $limit
    );
    $due->execute();
    $rows = $due->fetchAll();

    $remaining = $limit - count($rows);
    if ($remaining > 0) {
        $dueAttempts = $db->prepare(
            "SELECT 'attempt' AS source, ma.id, ma.gateway_id, ma.provider_message_id, ma.destination,
                    ma.delivery_status, ma.delivery_attempts, ma.attempted_at AS created_at,
                    ma.delivery_checked_at, '' AS sender
             FROM ellsms_message_attempts ma
             WHERE ma.status = 'accepted'
               AND ma.gateway_id IS NOT NULL
               AND ma.provider_message_id IS NOT NULL
               AND (ma.delivery_status IS NULL OR ma.delivery_status NOT IN ('delivered','failed','rejected','expired'))
             ORDER BY ma.delivery_checked_at IS NULL DESC, ma.id ASC
             LIMIT " . $remaining
        );
        $dueAttempts->execute();
        foreach ($dueAttempts->fetchAll() as $row) {
            $rows[] = $row;
        }
    }

    if ($rows === []) {
        return $stats;
    }

    // Group first, so each gateway's connector is compiled at most once for the whole pass.
    $byGateway = [];
    foreach ($rows as $row) {
        $byGateway[(int)$row['gateway_id']][] = $row;
    }

    foreach ($byGateway as $gatewayId => $gatewayRows) {
        // GATEWAY AFFINITY. The connector comes from the gateway_id RECORDED ON THE ROW — never from
        // re-resolving the route or falling back to the default. A provider message id is meaningful
        // only to the provider that issued it, so asking a different gateway about it would at best
        // return nothing and at worst match somebody else's message. This holds even if the route has
        // since been re-pointed at another gateway, which is exactly when it matters.
        $connector = gateway_compiled($gatewayId);
        if ($connector === null || !$connector['status_enabled'] || $connector['status'] === null) {
            // No status connector configured: these rows keep whatever state the send established.
            // Not an error — most gateways genuinely have no delivery API.
            $stats['skipped'] += count($gatewayRows);
            continue;
        }
        $status = $connector['status'];

        foreach ($gatewayRows as $row) {
            if (!gateway_status_row_is_due($row, $status)) {
                $stats['skipped']++;
                continue;
            }
            if (!gateway_status_claim((string)$row['source'], (int)$row['id'], $status)) {
                continue;   // another worker won the race for this row
            }
            $stats['claimed']++;

            $context = gateway_status_context([
                'provider_message_id' => (string)$row['provider_message_id'],
                'recipient'           => (string)($row['destination'] ?? ''),
                'sender'              => (string)($row['sender'] ?? ''),
                'gateway_code'        => $connector['gateway_code'],
            ]);
            $request = gateway_build_request($connector, 'status', $context, null, null);
            $response = gateway_execute($connector, 'status', $request);
            $stats['polled']++;

            if (!$response['ok']) {
                // A failed poll costs an attempt and nothing else. The delivery state is deliberately
                // untouched: "we could not ask" is not "not delivered".
                continue;
            }

            $providerStatus = gateway_path_extract($status['response']['provider_status'], $response['data']);
            $canonical = gateway_status_map($status['statuses'], is_scalar($providerStatus) ? (string)$providerStatus : null);
            $deliveredAt = gateway_status_delivered_at($status, $response['data'], $canonical);

            if (gateway_status_record((string)$row['source'], (int)$row['id'], $row['delivery_status'], $canonical, $deliveredAt)) {
                $stats['updated']++;
                if (gateway_state_is_terminal($canonical)) {
                    $stats['terminal']++;
                }
            }
        }
    }

    Logger::info('gateway.status.pass_completed', $stats);
    Metrics::increment('gateway_status_updates', $stats['updated']);
    return $stats;
}

/**
 * Whether a row is worth polling yet.
 *
 * Three separate limits, all from the connector: don't ask before the provider has had time to know
 * (poll_initial_delay_seconds), don't ask forever (poll_max_attempts), and don't ask about something
 * old enough that the answer no longer matters (poll_max_age_seconds). Without the last two, a
 * provider that never returns a terminal state would be polled indefinitely, once per pass, forever.
 */
function gateway_status_row_is_due(array $row, array $status): bool {
    $attempts = (int)$row['delivery_attempts'];
    if ($status['poll_max_attempts'] > 0 && $attempts >= $status['poll_max_attempts']) {
        return false;
    }
    $createdAt = strtotime((string)$row['created_at']) ?: 0;
    if ($status['poll_max_age_seconds'] > 0 && $createdAt > 0 && (time() - $createdAt) > $status['poll_max_age_seconds']) {
        return false;
    }
    // A configured 0 means "no delay" and is honoured as such — it is a deliberate choice an admin
    // made, and quietly substituting 30 would make the field lie. The default only applies when the
    // connector has no value at all.
    $delay = (int)($status['poll_initial_delay_seconds'] ?? GATEWAY_STATUS_DEFAULT_DELAY);
    $lastCheck = $row['delivery_checked_at'] === null ? null : (strtotime((string)$row['delivery_checked_at']) ?: null);
    $reference = $lastCheck ?? $createdAt;
    return $reference === 0 || (time() - $reference) >= $delay;
}

/**
 * Atomically claims one row for polling.
 *
 * The WHERE clause restates the same "not checked recently" condition the selection used, so this is
 * a genuine compare-and-swap: if another worker claimed the row in between, rowCount() is 0.
 */
function gateway_status_claim(string $source, int $rowId, array $status): bool {
    $delay = (int)($status['poll_initial_delay_seconds'] ?? GATEWAY_STATUS_DEFAULT_DELAY);
    $table = gateway_status_table($source);
    $claim = db()->prepare(
        "UPDATE {$table}
         SET delivery_checked_at = NOW(), delivery_attempts = delivery_attempts + 1
         WHERE id = ?
           AND (delivery_checked_at IS NULL OR delivery_checked_at <= DATE_SUB(NOW(), INTERVAL ? SECOND))"
    );
    $claim->execute([$rowId, $delay]);
    return $claim->rowCount() > 0;
}

/**
 * The table one polling source lives in.
 *
 * An explicit two-entry map rather than string interpolation of whatever arrived: the value reaches a
 * query, and a map cannot be talked into naming a third table no matter what a caller passes.
 */
function gateway_status_table(string $source): string {
    return match ($source) {
        'attempt' => 'ellsms_message_attempts',
        default   => 'ellsms_bulk_items',
    };
}

/** Reads the provider's delivery timestamp when it supplies one, else null. */
function gateway_status_delivered_at(array $status, mixed $data, string $canonical): ?string {
    if ($canonical !== 'delivered') {
        return null;
    }
    $path = $status['response']['delivered_at'] ?? [];
    $raw = $path === [] ? null : gateway_path_extract($path, $data);
    $timestamp = is_scalar($raw) ? strtotime((string)$raw) : false;
    // Falls back to "now" rather than leaving it null: the row IS delivered, and a delivered message
    // with no delivery time is harder to reason about than one timed at the moment we learned.
    return gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
}

/**
 * Writes a new delivery state, refusing any transition that would lose information.
 *
 * @return bool whether the state actually changed
 */
function gateway_status_record(string $source, int $rowId, ?string $current, string $next, ?string $deliveredAt): bool {
    if (!gateway_status_may_transition($current, $next)) {
        Logger::info('gateway.status.transition_refused', [
            'source' => $source, 'row_id' => $rowId, 'current' => $current, 'rejected' => $next,
        ]);
        return false;
    }
    if ($current === $next) {
        return false;
    }
    // The WHERE clause repeats the terminal-state guard in SQL, so two workers racing to write
    // different states cannot produce a downgrade even if both passed the PHP check.
    $table = gateway_status_table($source);
    $update = db()->prepare(
        "UPDATE {$table}
         SET delivery_status = ?, delivered_at = COALESCE(?, delivered_at)
         WHERE id = ?
           AND (delivery_status IS NULL OR delivery_status NOT IN ('delivered','failed','rejected','expired'))"
    );
    $update->execute([$next, $deliveredAt, $rowId]);
    if ($update->rowCount() === 0) {
        return false;
    }
    Logger::info('gateway.status.updated', ['source' => $source, 'row_id' => $rowId, 'from' => $current, 'to' => $next]);
    Metrics::increment('gateway_delivery_status', 1, ['state' => $next]);
    return true;
}
