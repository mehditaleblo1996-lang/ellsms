<?php
/**
 * ELLSMS — «مشتری ویژه» (VIP customers), docs/vip-customers.md.
 *
 * A platform admin ticks «مشتری ویژه» on a user (public/users.php). The flag lives on the user's own
 * ellsms_meta row (is_vip, with when and by whom it was set), is audited, and is never shown to the
 * customer or to resellers. It is used in two places:
 *
 *   - the admin customer list highlights VIP rows and can show only them;
 *   - the admin reports get a «مشتری ویژه» filter — همه / فقط مشتریان ویژه / بدون مشتریان ویژه — on the user the
 *     messages belong to: «گزارش تعداد پیام‌های ارسالی / دریافتی», «گزارش وضعیت‌ها», «لیست پیام‌های
 *     ارسالی» (and its CSV export) and «گزارش اپراتورها». VIP rows are highlighted where a report lists
 *     customers or messages.
 *
 * The flag is per user: the members of a VIP customer's organization are not VIP unless ticked too.
 *
 * Schema: db/migrations/2026_11_12_vip_customers.sql. Before it is applied nobody is VIP and the
 * filter is not offered.
 */

declare(strict_types=1);

/** «مشتری ویژه» report filter values: key => label. */
const VIP_FILTERS = ['' => 'همه', 'vip' => 'فقط مشتریان ویژه', 'regular' => 'بدون مشتریان ویژه'];

/** Whether ellsms_meta has is_vip (2026_11_12_vip_customers.sql). */
function vip_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            db()->query('SELECT is_vip FROM ellsms_meta LIMIT 0');
            $has = true;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Every VIP user's id, ascending — read once per request (vip_set() refreshes it).
 *
 * @return list<int>
 */
function vip_user_ids(bool $reload = false): array {
    static $ids = null;
    if ($ids !== null && !$reload) return $ids;
    $ids = [];
    if (vip_available()) {
        $ids = array_map('intval', db()->query('SELECT user_id FROM ellsms_meta WHERE is_vip = 1 ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
    }
    return $ids;
}

/** Whether $userId is a VIP customer. */
function vip_is(int $userId): bool {
    return in_array($userId, vip_user_ids(), true);
}

/**
 * Ticks or unticks «مشتری ویژه» for $userId (an existing ellsms_meta row). Audited; a no-op when
 * nothing changes.
 *
 * @return bool whether the flag changed
 */
function vip_set(int $userId, bool $vip, int $byUserId): bool {
    if (!vip_available() || $userId <= 0) return false;
    $st = db()->prepare('UPDATE ellsms_meta SET is_vip = ?, vip_marked_at = IF(? = 1, UTC_TIMESTAMP(), NULL), vip_marked_by = IF(? = 1, ?, NULL)
                         WHERE user_id = ? AND is_vip <> ?');
    $st->execute([(int)$vip, (int)$vip, (int)$vip, $byUserId, $userId, (int)$vip]);
    $changed = $st->rowCount() > 0;
    if ($changed) {
        audit($byUserId, $vip ? 'user.vip_on' : 'user.vip_off', 'user_id=' . $userId);
        vip_user_ids(true);
    }
    return $changed;
}

/**
 * When and by whom a user was marked VIP, or null when they are not.
 *
 * @return array{marked_at:?string,marked_by:?int}|null
 */
function vip_details(int $userId): ?array {
    if (!vip_available()) return null;
    $st = db()->prepare('SELECT is_vip, vip_marked_at, vip_marked_by FROM ellsms_meta WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (int)$row['is_vip'] !== 1) return null;
    return ['marked_at' => $row['vip_marked_at'] !== null ? (string)$row['vip_marked_at'] : null,
            'marked_by' => $row['vip_marked_by'] !== null ? (int)$row['vip_marked_by'] : null];
}

/** Pure: a «مشتری ویژه» filter value from a request — '' (all), 'vip' or 'regular' (everyone else). */
function vip_filter_from(array $query): string {
    $v = (string)($query['vip'] ?? '');
    return isset(VIP_FILTERS[$v]) ? $v : '';
}

/**
 * Pure: the SQL that keeps only the rows whose $userColumn passes $filter, given the VIP ids:
 * [' AND col IN (…)', ids] for VIP, [' AND col NOT IN (…)', ids] for regular, ['', []] for all.
 * VIP with nobody marked matches nothing; regular with nobody marked matches everything.
 *
 * @param list<int> $vipIds
 * @return array{0:string,1:list<int>}
 */
function vip_condition_sql(string $filter, array $vipIds, string $userColumn): array {
    if ($filter !== 'vip' && $filter !== 'regular') return ['', []];
    $vipIds = array_values(array_unique(array_map('intval', $vipIds)));
    if ($vipIds === []) return $filter === 'vip' ? [' AND 1=0', []] : ['', []];
    $in = implode(',', array_fill(0, count($vipIds), '?'));
    return [" AND {$userColumn} " . ($filter === 'vip' ? 'IN' : 'NOT IN') . " ({$in})", $vipIds];
}

/** vip_condition_sql() with the current VIP ids, its values appended to $params. */
function vip_user_condition(string $filter, string $userColumn, array &$params): string {
    if ($filter === '') return '';
    [$sql, $ids] = vip_condition_sql($filter, vip_user_ids(), $userColumn);
    array_push($params, ...$ids);
    return $sql;
}

/** Pure: whether a user passes $filter, given the VIP ids. */
function vip_filter_keeps(string $filter, int $userId, array $vipIds): bool {
    if ($filter === 'vip') return in_array($userId, $vipIds, true);
    if ($filter === 'regular') return !in_array($userId, $vipIds, true);
    return true;
}

/** The «مشتری ویژه» badge shown next to a VIP customer's name. */
function vip_badge(): string {
    return '<span class="badge badge-vip" title="مشتری ویژه">★ ویژه</span>';
}

/** The «مشتری ویژه» select for an admin report filter form. */
function vip_filter_select(string $current, string $name = 'vip'): string {
    if (!vip_available()) return '';
    $html = '<label>مشتری ویژه <select name="' . e($name) . '">';
    foreach (VIP_FILTERS as $key => $label) {
        $html .= '<option value="' . e($key) . '"' . ($current === $key ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select></label>';
}
