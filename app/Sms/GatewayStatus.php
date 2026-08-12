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
    $stats = ['claimed' => 0, 'polled' => 0, 'requests' => 0, 'updated' => 0,
             'terminal' => 0, 'skipped' => 0, 'unmatched' => 0];
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
                NULL AS route_id, NULL AS operator_id, bj.originator AS sender
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
                    ma.delivery_checked_at, ma.route_id, ma.operator_id, '' AS sender
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

        // Only rows that are actually due; the rest are left for a later pass.
        $due = [];
        foreach ($gatewayRows as $row) {
            if (!gateway_status_row_is_due($row, $status)) {
                $stats['skipped']++;
                continue;
            }
            $due[] = $row;
        }
        if ($due === []) {
            continue;
        }

        // Partition into requests. A batch-capable connector groups compatible rows; everything else
        // stays one row per request. Grouping NEVER crosses a gateway — that split already happened
        // above — and never crosses a route/operator override set or a differing context value.
        foreach (gateway_status_group_rows($connector, $due) as $group) {
            $claimed = [];
            foreach ($group['rows'] as $row) {
                if (gateway_status_claim((string)$row['source'], (int)$row['id'], $status)) {
                    $claimed[] = $row;
                    $stats['claimed']++;
                }
                // A row another worker claimed first is simply dropped from this request: including it
                // would ask about a message this pass does not own and then write a state for it.
            }
            if ($claimed === []) {
                continue;
            }

            $stats['requests']++;
            $stats['polled'] += count($claimed);
            $outcome = gateway_status_poll_group($connector, $group, $claimed);
            $stats['updated'] += $outcome['updated'];
            $stats['terminal'] += $outcome['terminal'];
            $stats['unmatched'] += $outcome['unmatched'];
        }
    }

    Logger::info('gateway.status.pass_completed', $stats);
    Metrics::increment('gateway_status_updates', $stats['updated']);
    return $stats;
}

/**
 * Partitions due rows into provider requests.
 *
 * A connector that cannot batch (no `provider_message_ids` parameter, or a parameter that reads a
 * per-message variable) yields one group per row — the pre-existing behaviour, unchanged.
 *
 * A batch-capable connector groups by everything that could make two rows' requests differ:
 * the route and operator override sets (via the compiled parameter signature) and the context values
 * a batched request has to share. Rows are never grouped across gateways, because the caller has
 * already split by gateway before calling this.
 *
 * Groups are capped at SMS_GATEWAY_STATUS_REQUEST_MAX ids so one provider request cannot grow
 * unbounded — a 5000-id POST is a timeout waiting to happen, and a provider rejecting an oversized
 * request would fail every message in it at once.
 *
 * @return list<array{rows:list<array>, route_id:?int, operator_id:?int}>
 */
function gateway_status_group_rows(array $connector, array $rows): array {
    $status = $connector['status'];

    if (empty($status['batch']['supported'])) {
        // One row per request. Deliberately not "batch of one": the per-message variables such a
        // connector reads (recipient, provider_message_id) are only meaningful for a single row.
        return array_map(
            static fn(array $row): array => [
                'rows' => [$row],
                'route_id' => isset($row['route_id']) ? (int)$row['route_id'] : null,
                'operator_id' => isset($row['operator_id']) ? (int)$row['operator_id'] : null,
            ],
            $rows
        );
    }

    $maxPerRequest = gateway_status_request_max();
    $groups = [];
    foreach ($rows as $row) {
        $routeId = isset($row['route_id']) ? (int)$row['route_id'] : null;
        $operatorId = isset($row['operator_id']) ? (int)$row['operator_id'] : null;
        // The signature covers the merged parameter DESCRIPTORS for this scope pair, so two rows whose
        // route/operator overrides differ in any way land in different requests.
        $signature = gateway_parameter_signature($connector, 'status', $routeId, $operatorId);

        $key = implode('|', [
            $connector['gateway_id'], $connector['config_version'],
            $routeId ?? '-', $operatorId ?? '-', $signature['signature'],
        ]);

        // Start a new group once the current one is full, so the cap applies per REQUEST rather than
        // per configuration.
        $index = count($groups[$key] ?? []) - 1;
        if ($index < 0 || count($groups[$key][$index]['rows']) >= $maxPerRequest) {
            $groups[$key][] = ['rows' => [], 'route_id' => $routeId, 'operator_id' => $operatorId];
            $index++;
        }
        $groups[$key][$index]['rows'][] = $row;
    }

    $flat = [];
    foreach ($groups as $chunks) {
        foreach ($chunks as $chunk) {
            $flat[] = $chunk;
        }
    }
    return $flat;
}

/** Maximum provider message ids in one status request. */
function gateway_status_request_max(): int {
    return max(1, (int)(env('SMS_GATEWAY_STATUS_REQUEST_MAX', '50') ?? '50'));
}

/**
 * Polls one group and writes each row's own result.
 *
 * @return array{updated:int, terminal:int, unmatched:int}
 */
function gateway_status_poll_group(array $connector, array $group, array $rows): array {
    $status = $connector['status'];
    $outcome = ['updated' => 0, 'terminal' => 0, 'unmatched' => 0];

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (string)$row['provider_message_id'];
    }
    $first = $rows[0];

    // Per-message context values are supplied ONLY for a single-row request. For a batch they are
    // deliberately absent rather than taken from the first row: a connector that reads them is not
    // batch-capable in the first place (gateway_status_batch_capability()), so an empty value here can
    // only ever reach a request that does not use it.
    $single = count($rows) === 1;
    $context = gateway_status_context([
        'provider_message_ids' => $ids,
        'provider_message_id'  => $single ? (string)$first['provider_message_id'] : '',
        'recipient'            => $single ? (string)($first['destination'] ?? '') : '',
        'sender'               => $single ? (string)($first['sender'] ?? '') : '',
        'gateway_code'         => $connector['gateway_code'],
    ]);

    $request = gateway_build_request($connector, 'status', $context, $group['route_id'], $group['operator_id']);
    $response = gateway_execute($connector, 'status', $request);

    if (!$response['ok']) {
        // A failed poll costs an attempt and nothing else — for EVERY row in the group. The delivery
        // state is deliberately untouched: "we could not ask" is not "not delivered". A provider-level
        // error (errorModel.errorCode != 0) lands here too, via the connector's success rule, so its
        // `states` are never read as delivery data.
        Logger::info('gateway.status.group_poll_failed', [
            'gateway_id' => $connector['gateway_id'], 'rows' => count($rows),
            'error_class' => $response['error_class'], 'http' => $response['http'],
        ]);
        Metrics::increment('gateway_status_poll_failure', count($rows), ['gateway' => $connector['gateway_code']]);
        return $outcome;
    }

    $states = gateway_status_extract_items($status, $response['data'], $ids);

    foreach ($rows as $row) {
        $id = (string)$row['provider_message_id'];
        if (!array_key_exists($id, $states['by_id'])) {
            // The provider answered, but said nothing about this message. It keeps its current state
            // and its attempt counter has already been incremented by the claim, so it is retried a
            // bounded number of times and then abandoned — never assigned a neighbour's status.
            $outcome['unmatched']++;
            continue;
        }

        $canonical = gateway_status_map($status['statuses'], $states['by_id'][$id]['status']);
        $deliveredAt = gateway_status_delivered_at_value($status, $states['by_id'][$id], $canonical);
        if (gateway_status_record((string)$row['source'], (int)$row['id'], $row['delivery_status'], $canonical, $deliveredAt)) {
            $outcome['updated']++;
            if (gateway_state_is_terminal($canonical)) {
                $outcome['terminal']++;
            }
        }
    }

    if ($outcome['unmatched'] > 0) {
        Logger::warning('gateway.status.items_missing', [
            'gateway_id' => $connector['gateway_id'],
            'requested' => count($ids), 'missing' => $outcome['unmatched'],
        ]);
        Metrics::increment('gateway_status_missing_items', $outcome['unmatched'], ['gateway' => $connector['gateway_code']]);
    }
    return $outcome;
}

/**
 * Correlates a provider answer to the ids that were requested, BY ID.
 *
 * Never by array position. A provider is free to answer in any order, to omit a message it has no
 * record of, or to include one nobody asked about — and position-based correlation turns every one of
 * those into a delivery state written onto the wrong message, silently.
 *
 * A DUPLICATE id in one response makes that id ambiguous, and it is dropped rather than resolved by
 * picking the first or last occurrence: two different answers for one message means the response
 * cannot be trusted about it, and choosing one arbitrarily would look identical to a correct answer.
 *
 * An UNKNOWN id (not requested) is counted and ignored. It cannot reach any row, because the loop that
 * writes states iterates over the REQUESTED rows and looks each one up here.
 *
 * @return array{by_id:array<string,array{status:?string,item:array}>, duplicates:int, unknown:int}
 */
function gateway_status_extract_items(array $status, mixed $data, array $requestedIds): array {
    $requested = array_flip(array_map('strval', $requestedIds));
    $items = $status['items'] ?? ['items_path' => []];

    // A single-message connector has no per-item mapping: the top-level provider_status path IS the
    // answer, and it belongs to the one id that was asked about.
    if (($items['items_path'] ?? []) === []) {
        $providerStatus = gateway_path_extract($status['response']['provider_status'], $data);
        $only = (string)($requestedIds[0] ?? '');
        return [
            'by_id' => $only === '' ? [] : [$only => [
                'status' => is_scalar($providerStatus) ? (string)$providerStatus : null,
                'item'   => is_array($data) ? $data : [],
            ]],
            'duplicates' => 0, 'unknown' => 0,
        ];
    }

    $rows = gateway_path_extract($items['items_path'], $data);
    if (!is_array($rows)) {
        return ['by_id' => [], 'duplicates' => 0, 'unknown' => 0];
    }

    $byId = [];
    $ambiguous = [];
    $duplicates = 0;
    $unknown = 0;

    foreach ($rows as $item) {
        if (!is_array($item) || !array_key_exists($items['id_key'], $item)) {
            continue;
        }
        // The id arrives as a STRING for anything wider than 2^53 (JSON_BIGINT_AS_STRING in
        // gateway_execute()), and as an int otherwise; both normalise to the same canonical decimal
        // the row stores, so correlation never depends on which side of that boundary a provider's
        // ids happen to fall.
        $id = gateway_decimal_token($item[$items['id_key']]) ?? (string)$item[$items['id_key']];

        if (!isset($requested[$id])) {
            $unknown++;
            continue;
        }
        if (array_key_exists($id, $byId) || isset($ambiguous[$id])) {
            // Two answers for one message: neither is trustworthy, so the id is dropped entirely and
            // the row is treated exactly like a missing one.
            unset($byId[$id]);
            $ambiguous[$id] = true;
            $duplicates++;
            continue;
        }

        $rawStatus = $item[$items['status_key']] ?? null;
        $byId[$id] = [
            'status' => is_scalar($rawStatus) ? (string)$rawStatus : null,
            'item'   => $item,
        ];
    }

    if ($duplicates > 0 || $unknown > 0) {
        // Diagnostics only — never an id value, which is customer-correlatable metadata.
        Logger::warning('gateway.status.response_anomaly', [
            'duplicate_ids' => $duplicates, 'unrequested_ids' => $unknown, 'requested' => count($requestedIds),
        ]);
        Metrics::increment('gateway_status_response_anomaly', $duplicates + $unknown);
    }
    return ['by_id' => $byId, 'duplicates' => $duplicates, 'unknown' => $unknown];
}

/** The delivery timestamp for one correlated item, or "now" when the provider supplies none. */
function gateway_status_delivered_at_value(array $status, array $entry, string $canonical): ?string {
    if ($canonical !== 'delivered') {
        return null;
    }
    $key = $status['items']['delivered_at_key'] ?? '';
    $raw = $key !== '' && isset($entry['item'][$key]) ? $entry['item'][$key] : null;
    if ($raw === null) {
        $path = $status['response']['delivered_at'] ?? [];
        $raw = $path === [] ? null : gateway_path_extract($path, $entry['item']);
    }
    $timestamp = is_scalar($raw) ? strtotime((string)$raw) : false;
    // Falls back to "now" rather than leaving it null: the row IS delivered, and a delivered message
    // with no delivery time is harder to reason about than one timed at the moment we learned.
    return gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
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
