<?php
/**
 * ELLSMS — preview, scheduling and one-click revert of price changes (docs/price-changes.md).
 *
 * Covers the price matrices (app/GatewayPrices.php): the global «تعرفه‌ی درگاه‌ها» (user slot 0) and
 * each account's «تعرفه‌ی اختصاصی» (user slot = the account id).
 *
 *   PREVIEW   Before anything is saved, the changed cells are priced against the last 30 days of real
 *             traffic (ellsms_price_usage_profile): once at today's prices and once "as if" the change
 *             were saved (gateway_prices_with_override()), through the very engine every send uses
 *             (sms_pricing_price_messages() — tariff percentages, own prices and reseller chains
 *             included). The difference is how many customers the change touches and how much it moves
 *             a month of revenue. An estimate: per-message rounding is not reproduced.
 *   APPLY     Now (one transaction: the matrix save and its ellsms_price_changes row), or at a chosen
 *             time: the change waits as 'scheduled' and the scheduler job `price-changes` applies it
 *             within a minute of that time.
 *   REVERT    An applied change is undone by a new change that puts back the values it replaced — only
 *             in cells still holding the value it set (a cell changed again since is left alone, and
 *             the revert says so). A scheduled change is cancelled instead.
 *
 * History is never rewritten: the matrix itself stays effective-dated (a revert is a new period, not
 * a deleted one), so sends priced in between keep their price.
 *
 * Schema: db/migrations/2026_11_20_price_changes.sql. Without it the matrices save directly, as before.
 */

declare(strict_types=1);

require_once __DIR__ . '/Reports/SentMessages.php';

/** Days of traffic the preview prices. */
const PRICE_USAGE_PROFILE_DAYS = 30;
/** ellsms_settings key holding when the profile was built and which days it covers (JSON). */
const PRICE_USAGE_PROFILE_META_SETTING = 'price_usage_profile_meta';
/** A preview's draft lives in the admin's session for this long. */
const PRICE_CHANGE_DRAFT_TTL_SECONDS = 3600;
/** How long one preview may spend pricing before it stops and says it is partial. */
const PRICE_CHANGE_PREVIEW_BUDGET_SECONDS = 20.0;
const PRICE_CHANGE_TOP_CUSTOMERS = 10;
const PRICE_CHANGE_STATUSES = [
    'scheduled' => 'زمان‌بندی‌شده',
    'applied'   => 'اعمال‌شده',
    'cancelled' => 'لغوشده',
    'reverted'  => 'برگشت‌خورده',
    'failed'    => 'ناموفق',
];

/** Whether 2026_11_20_price_changes.sql has been applied. Cached per process; $set is for tests. */
function price_changes_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_price_changes LIMIT 0');
            db()->query('SELECT 1 FROM ellsms_price_usage_profile LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/** Pure: the cells of $cells whose value differs from $current (key => millicredits | null). */
function price_change_diff(array $cells, array $current): array {
    $out = [];
    foreach ($cells as $key => $millis) {
        if (($current[(string)$key] ?? null) !== $millis) {
            $out[(string)$key] = $millis;
        }
    }
    return $out;
}

/** The matrix id of a slot for app/GatewayPrices.php: null for the global matrix (slot 0). */
function price_change_matrix_user(int $slot): ?int {
    return $slot === 0 ? null : $slot;
}

/* ==========================================================================
   Usage profile: the last 30 days of charged sends
   ========================================================================== */

/** SQL: 'fa' when $content needs UCS-2 (sms_parts()'s rule), else 'en'; a text never stored counts as 'fa'. */
function price_usage_encoding_sql(string $content): string {
    $c = "COALESCE({$content}, '')";
    return "(CASE WHEN {$content} IS NULL THEN 'fa' WHEN OCTET_LENGTH({$c}) <> CHAR_LENGTH({$c}) OR {$c} REGEXP '[^ -~\r\n]' THEN 'fa' ELSE 'en' END)";
}

/**
 * Rebuilds ellsms_price_usage_profile from the PRICE_USAGE_PROFILE_DAYS Tehran days before $toDay
 * ('Y-m-d', default today): every message the provider took — bulk rows, direct/API/scheduled/
 * auto-reply attempts and, where the backend's table exists, legacy sends — grouped by account,
 * sending line, destination operator and encoding. Platform admins are left out: they are never
 * charged. Returns the profile's meta (price_usage_profile_meta()).
 *
 * @return array{from:string, to:string, built_at:string, groups:int, messages:int}
 */
function price_usage_profile_build(?string $toDay = null): array {
    $toDay ??= (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $fromDay = (new DateTimeImmutable($toDay))->modify('-' . PRICE_USAGE_PROFILE_DAYS . ' days')->format('Y-m-d');
    [$fromUtc] = local_day_range_to_utc($fromDay, $fromDay);
    [$toUtc] = local_day_range_to_utc($toDay, $toDay);
    $db = db();

    $admins = array_flip(array_map('intval', $db->query('SELECT user_id FROM ellsms_meta WHERE is_admin = 1')->fetchAll(PDO::FETCH_COLUMN)));
    $groups = [];
    $add = static function (array $row) use (&$groups, $admins): void {
        $userId = (int)$row['user_id'];
        if ($userId <= 0 || isset($admins[$userId])) {
            return;
        }
        $sender = mb_substr((string)$row['sender'], 0, 32);
        $op = substr((string)$row['op'], 0, 24);
        $enc = (string)$row['enc'] === 'en' ? 'en' : 'fa';
        $key = $userId . "\0" . $sender . "\0" . $op . "\0" . $enc;
        if (!isset($groups[$key])) {
            $groups[$key] = ['user_id' => $userId, 'sender' => $sender, 'operator_key' => $op, 'encoding' => $enc,
                             'sample_mobile' => substr((string)$row['sample'], 0, 20), 'message_count' => 0, 'part_count' => 0, 'charged_credits' => 0];
        }
        $groups[$key]['message_count'] += (int)$row['n'];
        $groups[$key]['part_count'] += (int)$row['p'];
        $groups[$key]['charged_credits'] += (int)$row['charged'];
    };

    $bulkParts = message_parts_sql('ellsms_bulk_items', 'i', 'i.content');
    $st = $db->prepare("SELECT j.user_id, j.originator sender,
                               COALESCE(CAST(i.operator_id AS CHAR), CONCAT('p', LEFT(i.mobile, 5))) op,
                               " . price_usage_encoding_sql('i.content') . " enc,
                               COUNT(*) n, SUM({$bulkParts}) p, MIN(i.mobile) sample, SUM(COALESCE(i.price_cost_credits, 0)) charged
                        FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
                        WHERE i.created_at >= ? AND i.created_at < ? AND i.status = 'sent'
                        GROUP BY j.user_id, j.originator, op, enc");
    $st->execute([$fromUtc, $toUtc]);
    foreach ($st as $row) {
        $add($row);
    }

    $report = backend_message_attempts_have_report_columns();
    $content = $report ? 'a.content' : 'NULL';
    $attemptParts = message_parts_sql('ellsms_message_attempts', 'a', $content);
    $charged = delivery_billing_available() ? 'SUM(COALESCE(a.charged_credits, 0))' : '0';
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $st = $db->prepare("SELECT a.user_id, " . ($report ? "COALESCE(a.originator, '')" : "''") . " sender,
                               COALESCE(CAST(a.operator_id AS CHAR), CONCAT('p', LEFT(a.destination, 5))) op,
                               " . price_usage_encoding_sql($content) . " enc,
                               COUNT(*) n, SUM({$attemptParts}) p, MIN(a.destination) sample, {$charged} charged
                        FROM ellsms_message_attempts a
                        WHERE a.attempted_at >= ? AND a.attempted_at < ? AND a.status = 'accepted'
                          AND a.destination IS NOT NULL AND a.reference_type IN ({$types})
                        GROUP BY a.user_id, sender, op, enc");
    $st->execute([$fromUtc, $toUtc]);
    foreach ($st as $row) {
        $add($row);
    }

    if (sent_report_legacy_available()) {
        $st = $db->prepare("SELECT m.sender_user_id user_id, m.originator sender, CONCAT('p', LEFT(m.destination, 5)) op,
                                   " . price_usage_encoding_sql('m.content') . " enc,
                                   COUNT(*) n, SUM(" . sms_parts_sql('m.content') . ") p, MIN(m.destination) sample, 0 charged
                            FROM outbound_message m
                            WHERE m.sent_at >= ? AND m.sent_at < ? AND m.status IN ('sent','delivered')
                            GROUP BY m.sender_user_id, m.originator, op, enc");
        $st->execute([$fromUtc, $toUtc]);
        foreach ($st as $row) {
            $add($row);
        }
    }

    $messages = 0;
    db_transaction(function (PDO $db) use ($groups, &$messages): void {
        $db->exec('DELETE FROM ellsms_price_usage_profile');
        foreach (array_chunk(array_values($groups), 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $g) {
                $values[] = '(?,?,?,?,?,?,?,?)';
                array_push($params, $g['user_id'], $g['sender'], $g['operator_key'], $g['encoding'], $g['sample_mobile'],
                    $g['message_count'], $g['part_count'], $g['charged_credits']);
                $messages += $g['message_count'];
            }
            $db->prepare('INSERT INTO ellsms_price_usage_profile
                          (user_id, sender, operator_key, encoding, sample_mobile, message_count, part_count, charged_credits)
                          VALUES ' . implode(',', $values))->execute($params);
        }
    });
    $meta = ['from' => $fromDay, 'to' => $toDay, 'built_at' => gmdate('Y-m-d H:i:s'), 'groups' => count($groups), 'messages' => $messages];
    set_setting(PRICE_USAGE_PROFILE_META_SETTING, (string)json_encode($meta));
    Logger::info('price_usage_profile.built', $meta);
    return $meta;
}

/**
 * When the profile was built and which days it covers: from (first day), to (the day after the last,
 * Tehran), built_at (UTC), groups, messages. Null when it was never built.
 *
 * @return ?array{from:string, to:string, built_at:string, groups:int, messages:int}
 */
function price_usage_profile_meta(): ?array {
    $raw = setting_fresh(PRICE_USAGE_PROFILE_META_SETTING);
    $meta = $raw !== null ? json_decode($raw, true) : null;
    return is_array($meta) && isset($meta['from'], $meta['to'], $meta['built_at']) ? $meta : null;
}

/** Profile rows, the busiest first; $userIds narrows them to those accounts. */
function price_usage_profile_rows(?array $userIds = null): array {
    if ($userIds === []) {
        return [];
    }
    $sql = 'SELECT * FROM ellsms_price_usage_profile';
    $params = [];
    if ($userIds !== null) {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $sql .= ' WHERE user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
        $params = $userIds;
    }
    $st = db()->prepare($sql . ' ORDER BY part_count DESC, user_id, sender');
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * The accounts an account's own matrix (slot) can price: itself and every account below it
 * (sub-organizations pay their parent's prices; a reseller's own price is the floor of its customers').
 *
 * @return list<int>
 */
function price_change_accounts_under(int $slot): array {
    $ids = [$slot];
    if (!customer_hierarchy_available()) {
        return $ids;
    }
    $frontier = [$slot];
    $seen = [$slot => true];
    for ($depth = 0; $depth < CUSTOMER_MAX_DEPTH && $frontier !== []; $depth++) {
        $st = db()->prepare('SELECT user_id FROM ellsms_customer_accounts WHERE parent_user_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')');
        $st->execute($frontier);
        $frontier = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $child) {
            $child = (int)$child;
            if (!isset($seen[$child])) {
                $seen[$child] = true;
                $ids[] = $child;
                $frontier[] = $child;
            }
        }
    }
    return $ids;
}

/* ==========================================================================
   Impact
   ========================================================================== */

/**
 * What saving $cells (key => millicredits | null, only the cells that change) into matrix $slot would
 * do to a month of real traffic. Builds the usage profile first if it was never built.
 *
 * Every profile group is priced with one of its real recipients as a stand-in, at today's prices and
 * with the change, as the account that sent it. Its monthly cost is parts × unit price.
 *
 * @return array{slot:int, cells:int, from:?string, to:?string, built_at:?string, customers:int, customers_total:int,
 *               messages:int, parts:int, before:int, after:int, delta:int, revenue_total:int, percent:?float,
 *               newly_unpriced_messages:int, newly_priced_messages:int, top:list<array>, partial:bool,
 *               covered_percent:float, elapsed_ms:int}
 */
function price_change_impact(int $slot, array $cells): array {
    $started = microtime(true);
    $meta = price_usage_profile_meta() ?? price_usage_profile_build();
    $rows = price_usage_profile_rows($slot === 0 ? null : price_change_accounts_under($slot));
    $byPair = [];
    $totalParts = 0;
    foreach ($rows as $row) {
        $byPair[(int)$row['user_id'] . "\0" . (string)$row['sender']][] = $row;
        $totalParts += (int)$row['part_count'];
    }

    $now = gmdate('Y-m-d H:i:s');
    $override = [$slot => $cells];
    $perUser = [];
    $totals = ['messages' => 0, 'parts' => 0, 'before' => 0, 'after' => 0, 'revenue' => 0, 'unpriced' => 0, 'priced' => 0];
    $coveredParts = 0;
    $partial = false;

    $work = function () use ($byPair, $now, $override, &$perUser, &$totals, &$coveredParts, &$partial, $started): void {
        foreach ($byPair as $groupRows) {
            if (microtime(true) - $started > PRICE_CHANGE_PREVIEW_BUDGET_SECONDS) {
                $partial = true;
                break;
            }
            $userId = (int)$groupRows[0]['user_id'];
            $sender = (string)$groupRows[0]['sender'];
            $messages = [];
            foreach ($groupRows as $i => $g) {
                $mobile = normalize_msisdn((string)$g['sample_mobile']);
                if ($mobile !== null) {
                    $messages[$i] = ['mobile' => $mobile, 'segments' => 1, 'encoding' => (string)$g['encoding']];
                }
            }
            $price = static fn(): array => $messages === [] ? ['per_index' => []]
                : sms_pricing_price_messages($messages, $sender, null, $now, false, $userId);
            // Both passes inside an override scope, so the second reads the matrices the first read.
            $old = gateway_prices_with_override([], $price);
            $new = gateway_prices_with_override($override, $price);
            foreach ($groupRows as $i => $g) {
                $parts = (int)$g['part_count'];
                $coveredParts += $parts;
                $before = isset($old['per_index'][$i]) ? (int)$old['per_index'][$i]['unit_price'] : null;
                $after = isset($new['per_index'][$i]) ? (int)$new['per_index'][$i]['unit_price'] : null;
                $totals['revenue'] += $parts * ($before ?? 0);
                if ($before === $after) {
                    continue;
                }
                $count = (int)$g['message_count'];
                if ($after === null) {
                    $totals['unpriced'] += $count;
                } elseif ($before === null) {
                    $totals['priced'] += $count;
                }
                $totals['messages'] += $count;
                $totals['parts'] += $parts;
                $totals['before'] += $parts * ($before ?? 0);
                $totals['after'] += $parts * ($after ?? 0);
                $perUser[$userId] ??= ['user_id' => $userId, 'messages' => 0, 'parts' => 0, 'before' => 0, 'after' => 0];
                $perUser[$userId]['messages'] += $count;
                $perUser[$userId]['parts'] += $parts;
                $perUser[$userId]['before'] += $parts * ($before ?? 0);
                $perUser[$userId]['after'] += $parts * ($after ?? 0);
            }
        }
    };
    // One outer override scope keeps every matrix read for the whole preview (gateway_prices_at()).
    Metrics::muted(static fn() => gateway_prices_with_override([], $work));

    $rial = static fn(int $millis): int => (int)round($millis / 1000);
    uasort($perUser, static fn(array $a, array $b): int => abs($b['after'] - $b['before']) <=> abs($a['after'] - $a['before']));
    $top = array_slice(array_values($perUser), 0, PRICE_CHANGE_TOP_CUSTOMERS);
    $names = backend_users_by_ids(array_column($top, 'user_id'));
    foreach ($top as &$t) {
        $t['username'] = isset($names[$t['user_id']]) ? (string)$names[$t['user_id']]['username'] : '#' . $t['user_id'];
        $t['before'] = $rial($t['before']);
        $t['after'] = $rial($t['after']);
        $t['delta'] = $t['after'] - $t['before'];
    }
    unset($t);

    $before = $rial($totals['before']);
    $after = $rial($totals['after']);
    $revenue = $rial($totals['revenue']);
    return [
        'slot' => $slot,
        'cells' => count($cells),
        'from' => $meta['from'] ?? null,
        'to' => $meta['to'] ?? null,
        'built_at' => $meta['built_at'] ?? null,
        'customers' => count($perUser),
        'customers_total' => count(array_unique(array_map(static fn(array $r): int => (int)$r['user_id'], $rows))),
        'messages' => $totals['messages'],
        'parts' => $totals['parts'],
        'before' => $before,
        'after' => $after,
        'delta' => $after - $before,
        'revenue_total' => $revenue,
        'percent' => $revenue > 0 ? round(($after - $before) * 100 / $revenue, 2) : null,
        'newly_unpriced_messages' => $totals['unpriced'],
        'newly_priced_messages' => $totals['priced'],
        'top' => $top,
        'partial' => $partial,
        'covered_percent' => $totalParts > 0 ? round($coveredParts * 100 / $totalParts, 1) : 100.0,
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
    ];
}

/* ==========================================================================
   Applying, scheduling, cancelling, reverting
   ========================================================================== */

/**
 * Saves $cells into matrix $slot now and records the change. $impact is the preview's estimate;
 * $revertsId marks a change that undoes another. Returns gateway_prices_save()'s result plus
 * 'change_id' (null when nothing changed or the history table is missing).
 *
 * @return array{ok:bool, reason:?string, changed:int, before:array, after:array, change_id:?int}
 */
function price_change_apply_now(int $slot, array $cells, int $actorId, ?array $impact = null, ?int $revertsId = null): array {
    return db_transaction(function (PDO $db) use ($slot, $cells, $actorId, $impact, $revertsId): array {
        $result = gateway_prices_save(price_change_matrix_user($slot), $cells, $actorId);
        $result['change_id'] = null;
        if (!$result['ok'] || $result['changed'] === 0 || !price_changes_available()) {
            return $result;
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare("INSERT INTO ellsms_price_changes
                      (user_slot, status, cells_json, before_json, effective_at, impact_json, reverts_change_id, created_by, applied_at)
                      VALUES (?, 'applied', ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$slot, price_change_json($result['after']), price_change_json($result['before']), $now,
                      $impact !== null ? price_change_json($impact) : null, $revertsId, $actorId, $now]);
        $result['change_id'] = (int)$db->lastInsertId();
        audit($actorId, 'price_change.applied', "#{$result['change_id']} slot={$slot} cells={$result['changed']}" . ($revertsId !== null ? " reverts=#{$revertsId}" : ''));
        return $result;
    });
}

/**
 * Stores $cells to be saved into matrix $slot at $effectiveAtUtc ('Y-m-d H:i:s', UTC, in the future).
 * Validated now with the same rules as a save; validated again when it is applied.
 *
 * @return array{ok:bool, reason:?string, change_id:?int}
 */
function price_change_schedule(int $slot, array $cells, string $effectiveAtUtc, int $actorId, ?array $impact = null): array {
    if (!price_changes_available()) {
        return ['ok' => false, 'reason' => 'not_migrated', 'change_id' => null];
    }
    if ($cells === []) {
        return ['ok' => false, 'reason' => 'nothing_changed', 'change_id' => null];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $effectiveAtUtc) || $effectiveAtUtc <= gmdate('Y-m-d H:i:s')) {
        return ['ok' => false, 'reason' => 'not_in_future', 'change_id' => null];
    }
    $invalid = gateway_prices_validate(price_change_matrix_user($slot), $cells);
    if ($invalid !== null) {
        return ['ok' => false, 'reason' => $invalid, 'change_id' => null];
    }
    db()->prepare("INSERT INTO ellsms_price_changes (user_slot, status, cells_json, effective_at, impact_json, created_by)
                   VALUES (?, 'scheduled', ?, ?, ?, ?)")
        ->execute([$slot, price_change_json($cells), $effectiveAtUtc, $impact !== null ? price_change_json($impact) : null, $actorId]);
    $id = (int)db()->lastInsertId();
    audit($actorId, 'price_change.scheduled', "#{$id} slot={$slot} cells=" . count($cells) . " at={$effectiveAtUtc}");
    return ['ok' => true, 'reason' => null, 'change_id' => $id];
}

/** Cancels a change still waiting for its time. False when it is not (or no longer) scheduled. */
function price_change_cancel(int $id, int $actorId, ?int $slot = null): bool {
    if (!price_changes_available()) {
        return false;
    }
    $sql = "UPDATE ellsms_price_changes SET status = 'cancelled', cancelled_by = ?, cancelled_at = ? WHERE id = ? AND status = 'scheduled'";
    $params = [$actorId, gmdate('Y-m-d H:i:s'), $id];
    if ($slot !== null) {
        $sql .= ' AND user_slot = ?';
        $params[] = $slot;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    if ($st->rowCount() === 1) {
        audit($actorId, 'price_change.cancelled', "#{$id}");
        return true;
    }
    return false;
}

/**
 * Applies every scheduled change whose time has come, oldest first. Each is applied in its own
 * transaction, locked, so a second runner (or a cancel at the same moment) can never apply it twice.
 * A change that no longer validates (a gateway archived since, an account moved under a reseller) is
 * marked 'failed' with the reason.
 *
 * @return array{applied:int, failed:int, unchanged:int}
 */
function price_changes_apply_due(?string $nowUtc = null): array {
    $out = ['applied' => 0, 'failed' => 0, 'unchanged' => 0];
    if (!price_changes_available()) {
        return $out;
    }
    $nowUtc ??= gmdate('Y-m-d H:i:s');
    $st = db()->prepare("SELECT id FROM ellsms_price_changes WHERE status = 'scheduled' AND effective_at <= ? ORDER BY effective_at, id");
    $st->execute([$nowUtc]);
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        $result = db_transaction(function (PDO $db) use ($id): string {
            $lock = $db->prepare("SELECT * FROM ellsms_price_changes WHERE id = ? AND status = 'scheduled' FOR UPDATE");
            $lock->execute([$id]);
            $change = $lock->fetch();
            if (!$change) {
                return 'gone';
            }
            $cells = price_change_decode((string)$change['cells_json']);
            $slot = (int)$change['user_slot'];
            $saved = gateway_prices_save(price_change_matrix_user($slot), $cells, (int)$change['created_by']);
            $now = gmdate('Y-m-d H:i:s');
            if (!$saved['ok']) {
                $db->prepare("UPDATE ellsms_price_changes SET status = 'failed', note = ? WHERE id = ?")
                   ->execute([mb_substr(gateway_price_error_message((string)$saved['reason']), 0, 500), $id]);
                Logger::warning('price_change.scheduled_failed', ['id' => $id, 'reason' => $saved['reason']]);
                return 'failed';
            }
            $db->prepare("UPDATE ellsms_price_changes SET status = 'applied', before_json = ?, applied_at = ?,
                                 note = IF(? = 0, 'همه‌ی خانه‌ها پیش از این زمان همین مقدار را داشتند.', NULL) WHERE id = ?")
               ->execute([price_change_json($saved['before']), $now, (int)$saved['changed'], $id]);
            audit((int)$change['created_by'], 'price_change.applied', "#{$id} slot={$slot} cells={$saved['changed']} scheduled");
            return $saved['changed'] > 0 ? 'applied' : 'unchanged';
        });
        if (isset($out[$result])) {
            $out[$result]++;
        }
    }
    if ($out['applied'] > 0) {
        sms_pricing_cache_reset();
    }
    return $out;
}

/**
 * The cells that undo applied change $change: for every cell it changed, the value it replaced —
 * only where the matrix still holds the value it set ($current, gateway_prices_current()).
 *
 * @return array{cells:array<string,?int>, skipped:list<string>}
 */
function price_change_revert_cells(array $change, array $current): array {
    $set = price_change_decode((string)$change['cells_json']);
    $before = price_change_decode((string)($change['before_json'] ?? '{}'));
    $cells = [];
    $skipped = [];
    foreach ($before as $key => $old) {
        if (($current[$key] ?? null) === ($set[$key] ?? null)) {
            if (($current[$key] ?? null) !== $old) {
                $cells[$key] = $old;
            }
        } else {
            $skipped[] = (string)$key;
        }
    }
    return ['cells' => $cells, 'skipped' => $skipped];
}

/** One change row, or null. $slot (when given) must match. */
function price_change_find(int $id, ?int $slot = null): ?array {
    if (!price_changes_available()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM ellsms_price_changes WHERE id = ?' . ($slot !== null ? ' AND user_slot = ?' : ''));
    $st->execute($slot !== null ? [$id, $slot] : [$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Undoes applied change $id now, as a new change, and marks it 'reverted'. Cells changed again since
 * are left as they are and listed in 'skipped'. Reasons: not_found, not_applied, nothing_to_revert,
 * or a gateway_prices_save() reason.
 *
 * @return array{ok:bool, reason:?string, change_id:?int, changed:int, skipped:list<string>}
 */
function price_change_revert(int $id, int $actorId, ?int $slot = null): array {
    $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'change_id' => null, 'changed' => 0, 'skipped' => []];
    $change = price_change_find($id, $slot);
    if ($change === null) {
        return $fail('not_found');
    }
    if ($change['status'] !== 'applied') {
        return $fail('not_applied');
    }
    $changeSlot = (int)$change['user_slot'];
    // The estimate is computed before taking any lock: it reads a month of traffic.
    $planned = price_change_revert_cells($change, gateway_prices_current(price_change_matrix_user($changeSlot)));
    if ($planned['cells'] === []) {
        return $fail('nothing_to_revert') + ['skipped' => $planned['skipped']];
    }
    $impact = price_change_impact($changeSlot, $planned['cells']);

    return db_transaction(function (PDO $db) use ($id, $actorId, $changeSlot, $impact, $fail): array {
        $lock = $db->prepare("SELECT * FROM ellsms_price_changes WHERE id = ? FOR UPDATE");
        $lock->execute([$id]);
        $change = $lock->fetch();
        if (!$change || $change['status'] !== 'applied') {
            return $fail('not_applied');
        }
        // Read again under the lock: the matrix may have changed while the estimate ran.
        $open = $db->prepare('SELECT gateway_slot, column_key, encoding, price_per_segment_millicredits FROM ellsms_gateway_prices
                              WHERE user_slot = ? AND effective_to IS NULL FOR UPDATE');
        $open->execute([$changeSlot]);
        $current = [];
        foreach ($open as $row) {
            $current[gateway_price_key((int)$row['gateway_slot'], (string)$row['column_key'], (string)$row['encoding'])] = (int)$row['price_per_segment_millicredits'];
        }
        $plan = price_change_revert_cells($change, $current);
        if ($plan['cells'] === []) {
            return $fail('nothing_to_revert');
        }
        $applied = price_change_apply_now($changeSlot, $plan['cells'], $actorId, $impact, $id);
        if (!$applied['ok']) {
            return $fail((string)$applied['reason']); // refused before anything was written
        }
        $note = $plan['skipped'] !== [] ? count($plan['skipped']) . ' خانه که بعد از این تغییر دوباره عوض شده بود دست نخورد.' : null;
        $db->prepare("UPDATE ellsms_price_changes SET status = 'reverted', reverted_by_change_id = ?, note = ? WHERE id = ?")
           ->execute([$applied['change_id'], $note, $id]);
        audit($actorId, 'price_change.reverted', "#{$id} by #{$applied['change_id']} cells={$applied['changed']} skipped=" . count($plan['skipped']));
        return ['ok' => true, 'reason' => null, 'change_id' => $applied['change_id'], 'changed' => (int)$applied['changed'], 'skipped' => $plan['skipped']];
    });
}

/** The last $limit changes of matrix $slot, newest first, with their cells, values replaced and estimate decoded. */
function price_changes_list(int $slot, int $limit = 15): array {
    if (!price_changes_available()) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM ellsms_price_changes WHERE user_slot = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$slot]);
    $rows = $st->fetchAll();
    $actors = backend_users_by_ids(array_column($rows, 'created_by'));
    foreach ($rows as &$row) {
        $row['cells'] = price_change_decode((string)$row['cells_json']);
        $row['before'] = $row['before_json'] !== null ? price_change_decode((string)$row['before_json']) : null;
        $row['impact'] = $row['impact_json'] !== null ? json_decode((string)$row['impact_json'], true) : null;
        $row['actor'] = isset($actors[(int)$row['created_by']]) ? (string)$actors[(int)$row['created_by']]['username'] : '#' . $row['created_by'];
    }
    unset($row);
    return $rows;
}

function price_change_error_message(string $reason): string {
    return [
        'not_migrated'      => 'برای پیش‌نمایش و زمان‌بندی تعرفه ابتدا مهاجرت 2026_11_20_price_changes.sql را اجرا کنید.',
        'nothing_changed'   => 'تغییری در تعرفه نبود.',
        'not_in_future'     => 'زمان اجرا باید در آینده باشد.',
        'not_found'         => 'این تغییر پیدا نشد.',
        'not_applied'       => 'فقط تغییری که اعمال شده و هنوز برنگشته قابل بازگشت است.',
        'nothing_to_revert' => 'همه‌ی خانه‌های این تغییر بعداً دوباره عوض شده‌اند؛ چیزی برای برگرداندن نمانده است.',
        'draft_expired'     => 'پیش‌نمایش منقضی شده است؛ تعرفه را دوباره وارد کنید.',
    ][$reason] ?? gateway_price_error_message($reason);
}

/* ==========================================================================
   Display
   ========================================================================== */

/**
 * Labels for the cells of a matrix: gateway row (with the default and shared-service rows), destination
 * column and encoding. One read of each catalog per call.
 *
 * @return callable(string): array{row:string, column:string, encoding:string}
 */
function price_change_cell_labeler(): callable {
    $rows = [GATEWAY_PRICE_ALL => 'پیش‌فرض همه‌ی درگاه‌ها'] + GATEWAY_PRICE_SHARED_ROWS;
    try {
        foreach (db()->query('SELECT id, name FROM ellsms_sms_gateways') as $g) {
            $rows[(int)$g['id']] = (string)$g['name'];
        }
    } catch (PDOException) {
    }
    $columns = ['other' => 'سایر', 'intl' => 'بین‌الملل'];
    try {
        foreach (db()->query('SELECT id, name FROM ellsms_sms_operators') as $o) {
            $columns[(string)$o['id']] = (string)$o['name'];
        }
    } catch (PDOException) {
    }
    return static function (string $key) use ($rows, $columns): array {
        [$gateway, $column, $encoding] = array_pad(explode('|', $key), 3, '');
        return [
            'row' => $rows[(int)$gateway] ?? ('درگاه #' . $gateway),
            'column' => $columns[$column] ?? $column,
            'encoding' => GATEWAY_PRICE_ENCODINGS[$encoding] ?? $encoding,
        ];
    };
}

/** Pure: a price in millicredits as Rials for display ("1,200" / "850.5"), or «پیش‌فرض» for none. */
function price_change_format_price(?int $millis): string {
    return $millis === null ? 'پیش‌فرض' : to_persian_digits(report_format_millicredits($millis));
}

/** Pure: a signed Rial amount ("+12,000" / "−3,500"). */
function price_change_format_delta(int $rial): string {
    return ($rial > 0 ? '+' : ($rial < 0 ? '−' : '')) . to_persian_digits(number_format(abs($rial)));
}

/* ==========================================================================
   The preview draft (admin's session)
   ========================================================================== */

/** Keeps a previewed change in the session until it is confirmed or dropped. Returns its token. */
function price_change_draft_put(int $slot, array $cells, array $impact): string {
    $token = bin2hex(random_bytes(12));
    $_SESSION['price_change_drafts'][$slot] = ['token' => $token, 'cells' => $cells, 'impact' => $impact, 'created' => time()];
    return $token;
}

/** The previewed change of matrix $slot still waiting for confirmation, or null. */
function price_change_draft(int $slot): ?array {
    $draft = $_SESSION['price_change_drafts'][$slot] ?? null;
    if (!is_array($draft) || time() - (int)($draft['created'] ?? 0) > PRICE_CHANGE_DRAFT_TTL_SECONDS) {
        return null;
    }
    return $draft;
}

/** Takes the draft out of the session when $token is its own; null when it expired or is another one. */
function price_change_draft_take(int $slot, string $token): ?array {
    $draft = price_change_draft($slot);
    unset($_SESSION['price_change_drafts'][$slot]);
    return $draft !== null && hash_equals((string)$draft['token'], $token) ? $draft : null;
}

function price_change_draft_clear(int $slot): void {
    unset($_SESSION['price_change_drafts'][$slot]);
}

/**
 * Handles the price-change POST actions of a matrix page for $slot: preview (do = $previewDo, the
 * matrix form itself), confirm, discard, cancel and revert. Returns true when $do was one of them
 * (the caller then redirects); flashes the outcome.
 */
function price_change_handle_post(string $do, int $slot, array $me, string $previewDo): bool {
    $actor = (int)$me['id'];
    $matrixUser = price_change_matrix_user($slot);
    switch ($do) {
        case $previewDo:
            [$cells, $error] = gateway_prices_from_form((array)($_POST['price'] ?? []));
            if ($error !== null) {
                flash('error', gateway_price_error_message($error));
                return true;
            }
            $cells = gateway_prices_drop_defaults($cells, gateway_prices_current($matrixUser), gateway_price_defaults($matrixUser));
            $changes = price_change_diff($cells, gateway_prices_current($matrixUser));
            if ($changes === []) {
                price_change_draft_clear($slot);
                flash('info', 'تغییری در تعرفه نبود.');
                return true;
            }
            $invalid = gateway_prices_validate($matrixUser, $changes);
            if ($invalid !== null) {
                flash('error', gateway_price_error_message($invalid));
                return true;
            }
            if (!price_changes_available()) {
                // No history table: save directly, as before this feature.
                $result = gateway_prices_save($matrixUser, $changes, $actor);
                flash($result['ok'] ? 'success' : 'error', $result['ok']
                    ? 'تعرفه ذخیره شد (' . to_persian_digits((string)$result['changed']) . ' خانه). ' . price_change_error_message('not_migrated')
                    : gateway_price_error_message((string)$result['reason']));
                return true;
            }
            price_change_draft_put($slot, $changes, price_change_impact($slot, $changes));
            flash('info', 'پیش‌نمایش اثر تغییر آماده است؛ پس از بررسی، آن را تأیید یا زمان‌بندی کنید.');
            return true;

        case 'price_change_confirm':
            $draft = price_change_draft_take($slot, (string)($_POST['token'] ?? ''));
            if ($draft === null) {
                flash('error', price_change_error_message('draft_expired'));
                return true;
            }
            if (($_POST['when'] ?? 'now') === 'later') {
                $date = jalali_request_to_gregorian('effective');
                $hour = (int)($_POST['effective_time_h'] ?? -1);
                $minute = (int)($_POST['effective_time_i'] ?? -1);
                $at = price_change_local_to_utc($date, $hour, $minute);
                $result = $at === null ? ['ok' => false, 'reason' => 'not_in_future'] : price_change_schedule($slot, $draft['cells'], $at, $actor, $draft['impact']);
                if (!$result['ok']) {
                    price_change_draft_put($slot, $draft['cells'], $draft['impact']); // keep the preview to try another time
                }
                flash($result['ok'] ? 'success' : 'error', $result['ok']
                    ? 'تغییر تعرفه برای ' . jdate_from_utc($at) . ' زمان‌بندی شد؛ تا آن زمان قیمت‌ها همان قبلی است.'
                    : price_change_error_message((string)$result['reason']));
                return true;
            }
            $result = price_change_apply_now($slot, $draft['cells'], $actor, $draft['impact']);
            flash($result['ok'] ? 'success' : 'error', $result['ok']
                ? ($result['changed'] > 0
                    ? 'تعرفه ذخیره شد (' . to_persian_digits((string)$result['changed']) . ' خانه) و از همین لحظه برای ارسال‌های جدید اعمال می‌شود.'
                    : 'تغییری در تعرفه نبود.')
                : gateway_price_error_message((string)$result['reason']));
            return true;

        case 'price_change_discard':
            price_change_draft_clear($slot);
            flash('info', 'پیش‌نمایش کنار گذاشته شد؛ چیزی ذخیره نشد.');
            return true;

        case 'price_change_cancel':
            $ok = price_change_cancel((int)($_POST['change_id'] ?? 0), $actor, $slot);
            flash($ok ? 'success' : 'error', $ok ? 'تغییر زمان‌بندی‌شده لغو شد.' : 'این تغییر دیگر در انتظار اجرا نیست.');
            return true;

        case 'price_change_revert':
            $result = price_change_revert((int)($_POST['change_id'] ?? 0), $actor, $slot);
            if ($result['ok']) {
                flash('success', 'تغییر برگشت داده شد (' . to_persian_digits((string)$result['changed']) . ' خانه به مقدار قبلی برگشت)'
                    . ($result['skipped'] !== [] ? '؛ ' . to_persian_digits((string)count($result['skipped'])) . ' خانه که بعداً دوباره عوض شده بود دست نخورد.' : '.'));
            } else {
                flash('error', price_change_error_message((string)$result['reason']));
            }
            return true;
    }
    return false;
}

/** Pure: a Tehran date ('Y-m-d') and time as the UTC 'Y-m-d H:i:s', or null when any part is missing. */
function price_change_local_to_utc(?string $date, int $hour, int $minute): ?string {
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        return null;
    }
    return (new DateTimeImmutable(sprintf('%s %02d:%02d:00', $date, $hour, $minute), new DateTimeZone('Asia/Tehran')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Pure: cells as stored JSON (keys kept as strings, null for a cleared cell). */
function price_change_json(array $data): string {
    return (string)json_encode($data === [] ? new stdClass() : $data, JSON_UNESCAPED_UNICODE);
}

/** Pure: stored cells back to key => millicredits | null. */
function price_change_decode(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key => $value) {
        $out[(string)$key] = $value === null ? null : (int)$value;
    }
    return $out;
}
