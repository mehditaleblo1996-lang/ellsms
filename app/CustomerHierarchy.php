<?php
/**
 * ELLSMS — resellers (نمایندگی) and organizational customers (مشتری سازمانی).
 *
 * Modeled on the Negar platform's customer types (RESELLER / ORGAN / NORMAL_CUSTOMER):
 *
 *   - A reseller creates sub-accounts. A level-0 reseller (one an admin created) may create normal
 *     customers, organizations and sub-resellers; a sub-reseller only normal customers and
 *     organizations.
 *   - An organization creates sub-organizations only, and a sub-organization (an organization whose
 *     parent is an organization) may not have sub-accounts. A sub-organization always pays its
 *     parent's tariff and, unless the parent sets another, expires with its parent.
 *   - Every parent has a sub-account quota (max_subset_limit). A child uses its own quota + 1 of
 *     the parent's.
 *   - A parent moves credit to a child and can take it back (app/wallet.php wallet_transfer()).
 *   - The admin sets a top-level reseller's prices («تعرفه‌ی اختصاصی», app/GatewayPrices.php). A
 *     reseller puts each child on one of its named tariffs (app/ResellerTariffs.php), never below
 *     its own price, and is paid the difference once a day (customer_profit_run()).
 *
 * tariff_bp is a legacy coefficient in basis points on the system price list (10000 = 100%). It is
 * no longer set from any panel and every account stays at 100%; it remains in the schema and in the
 * pricing/profit arithmetic only so older price snapshots keep their meaning.
 *
 * Schema: db/migrations/2026_10_12_customer_hierarchy.sql. Until it is applied every account reads as
 * a normal customer with a 100% tariff and nothing here changes behavior.
 */

declare(strict_types=1);

const CUSTOMER_TYPES = [
    'normal'   => 'عادی',
    'reseller' => 'نماینده',
    'organ'    => 'سازمانی',
];
const CUSTOMER_TARIFF_BASE_BP = 10000;
const CUSTOMER_TARIFF_MIN_BP  = 1000;    // 10% of the price list
const CUSTOMER_TARIFF_MAX_BP  = 100000;  // 1000% of the price list
const CUSTOMER_MAX_DEPTH      = 8;       // guards every walk up the parent chain
const CUSTOMER_PROFIT_BACKFILL_DAYS = 60;

/** Whether the hierarchy migration has been applied. Cached per process. */
function customer_hierarchy_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        $available = $set;
        return $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_customer_accounts LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

function customer_account_defaults(int $userId): array {
    return [
        'user_id'          => $userId,
        'customer_type'    => 'normal',
        'parent_user_id'   => null,
        'level'            => 0,
        'max_subset_limit' => 0,
        'tariff_bp'        => CUSTOMER_TARIFF_BASE_BP,
        'tariff_id'        => null,
        'expires_on'       => null,
        'suspended'        => 0,
        'exists'           => false,
    ];
}

function customer_account_normalize(array $row): array {
    return [
        'user_id'          => (int)$row['user_id'],
        'customer_type'    => (string)$row['customer_type'],
        'parent_user_id'   => $row['parent_user_id'] !== null ? (int)$row['parent_user_id'] : null,
        'level'            => (int)$row['level'],
        'max_subset_limit' => (int)$row['max_subset_limit'],
        'tariff_bp'        => (int)$row['tariff_bp'],
        // Named tariff (app/ResellerTariffs.php), present once 2026_10_24_reseller_panel.sql is applied.
        'tariff_id'        => isset($row['tariff_id']) ? (int)$row['tariff_id'] : null,
        'expires_on'       => $row['expires_on'] !== null ? (string)$row['expires_on'] : null,
        'suspended'        => (int)$row['suspended'],
        'exists'           => true,
    ];
}

/** The account's hierarchy row, or the defaults (a normal top-level customer) when it has none. */
function customer_account(int $userId, ?PDO $db = null, bool $forUpdate = false): array {
    if ($userId <= 0 || !customer_hierarchy_available()) {
        return customer_account_defaults($userId);
    }
    $st = ($db ?? db())->prepare('SELECT * FROM ellsms_customer_accounts WHERE user_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ? customer_account_normalize($row) : customer_account_defaults($userId);
}

/** @return array<int,array> keyed by user id; ids without a row get the defaults. */
function customer_accounts_by_ids(array $userIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = customer_account_defaults($id);
    }
    if (!$ids || !customer_hierarchy_available()) {
        return $out;
    }
    $st = db()->prepare('SELECT * FROM ellsms_customer_accounts WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['user_id']] = customer_account_normalize($row);
    }
    return $out;
}

/** A sub-organization: an organization whose parent is an organization. */
function customer_is_sub_organ(array $account, ?array $parentAccount = null): bool {
    if ($account['customer_type'] !== 'organ' || $account['parent_user_id'] === null) {
        return false;
    }
    $parentAccount ??= customer_account((int)$account['parent_user_id']);
    return $parentAccount['customer_type'] === 'organ';
}

/** Persian label, e.g. «نماینده», «زیرنماینده», «زیرسازمان». */
function customer_type_label(array $account, ?array $parentAccount = null): string {
    if ($account['customer_type'] === 'reseller') {
        return $account['level'] > 0 ? 'زیرنماینده' : 'نماینده';
    }
    if ($account['customer_type'] === 'organ') {
        return customer_is_sub_organ($account, $parentAccount) ? 'زیرسازمان' : 'سازمانی';
    }
    return CUSTOMER_TYPES['normal'];
}

/**
 * The customer types $parent may create, in Negar's rules.
 * @return list<string>
 */
function customer_allowed_child_types(array $parent, ?array $grandParent = null): array {
    if ($parent['customer_type'] === 'reseller') {
        return $parent['level'] === 0 ? ['normal', 'organ', 'reseller'] : ['normal', 'organ'];
    }
    if ($parent['customer_type'] === 'organ') {
        return customer_is_sub_organ($parent, $grandParent) ? [] : ['organ'];
    }
    return [];
}

function customer_can_have_children(array $account): bool {
    return customer_allowed_child_types($account) !== [];
}

/** A child's own quota, forced to zero for account types that may not have sub-accounts. */
function customer_child_max_subset(string $childType, int $requested, array $parent): int {
    if ($childType === 'normal' || ($childType === 'organ' && $parent['customer_type'] === 'organ')) {
        return 0;
    }
    return max(0, $requested);
}

/** How much of $parentId's quota its children use: each child its own quota + 1. */
function customer_used_subset(int $parentId, ?PDO $db = null): int {
    if (!customer_hierarchy_available()) return 0;
    $st = ($db ?? db())->prepare('SELECT COALESCE(SUM(max_subset_limit + 1), 0) FROM ellsms_customer_accounts WHERE parent_user_id = ?');
    $st->execute([$parentId]);
    return (int)$st->fetchColumn();
}

/** @return list<int> direct children's user ids */
function customer_child_ids(int $parentId): array {
    if (!customer_hierarchy_available()) return [];
    $st = db()->prepare('SELECT user_id FROM ellsms_customer_accounts WHERE parent_user_id = ? ORDER BY user_id DESC');
    $st->execute([$parentId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** @return list<int> the parent chain, nearest first, bounded by CUSTOMER_MAX_DEPTH */
function customer_ancestor_ids(int $userId): array {
    $chain = [];
    $current = customer_account($userId);
    while ($current['parent_user_id'] !== null && count($chain) < CUSTOMER_MAX_DEPTH) {
        $parentId = (int)$current['parent_user_id'];
        if ($parentId === $userId || in_array($parentId, $chain, true)) break; // corrupt data: never loop
        $chain[] = $parentId;
        $current = customer_account($parentId);
    }
    return $chain;
}

function customer_is_ancestor(int $ancestorId, int $userId): bool {
    return $ancestorId > 0 && $ancestorId !== $userId && in_array($ancestorId, customer_ancestor_ids($userId), true);
}

/** Today's date in Tehran, 'Y-m-d' — the calendar expiry dates are written in. */
function customer_today(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
}

/**
 * Why this account may not use the panel right now: 'suspended', 'expired', or null. A
 * sub-organization is also blocked while its parent organization is.
 */
function customer_account_block_reason(int $userId): ?string {
    if (!customer_hierarchy_available()) return null;
    $account = customer_account($userId);
    if (!$account['exists']) return null;
    if ($account['suspended']) return 'suspended';
    if ($account['expires_on'] !== null && $account['expires_on'] < customer_today()) return 'expired';
    if ($account['customer_type'] === 'organ' && $account['parent_user_id'] !== null) {
        $parent = customer_account((int)$account['parent_user_id']);
        if ($parent['customer_type'] === 'organ') {
            if ($parent['suspended']) return 'suspended';
            if ($parent['expires_on'] !== null && $parent['expires_on'] < customer_today()) return 'expired';
        }
    }
    return null;
}

function customer_block_message(string $reason): string {
    return [
        'suspended' => 'حساب شما غیرفعال شده است. با نماینده یا پشتیبانی تماس بگیرید.',
        'expired'   => 'مدت اعتبار حساب شما به پایان رسیده است. برای تمدید با نماینده یا پشتیبانی تماس بگیرید.',
    ][$reason] ?? 'حساب شما در حال حاضر قابل استفاده نیست.';
}

/* ==========================================================================
   Tariff
   ========================================================================== */

/** The coefficient this account is charged at: a sub-organization pays its parent's. */
function customer_effective_tariff_bp(int $userId): int {
    if ($userId <= 0 || !customer_hierarchy_available()) {
        return CUSTOMER_TARIFF_BASE_BP;
    }
    try {
        $account = customer_account($userId);
        if ($account['customer_type'] === 'organ' && $account['parent_user_id'] !== null) {
            $parent = customer_account((int)$account['parent_user_id']);
            if ($parent['customer_type'] === 'organ') {
                return $parent['tariff_bp'];
            }
        }
        return $account['tariff_bp'];
    } catch (PDOException $e) {
        Logger::warning('customer.tariff_lookup_failed', ['user_id' => $userId, 'exception' => $e]);
        return CUSTOMER_TARIFF_BASE_BP;
    }
}

/** A unit price on the price list, at a tariff coefficient. Rounded up to a whole millicredit. */
function customer_tariff_apply(int $unitPrice, int $tariffBp): int {
    if ($tariffBp === CUSTOMER_TARIFF_BASE_BP || $unitPrice <= 0) {
        return $unitPrice;
    }
    return intdiv($unitPrice * $tariffBp + CUSTOMER_TARIFF_BASE_BP - 1, CUSTOMER_TARIFF_BASE_BP);
}

/** '120' / '87.5' for a percentage form field, from basis points. */
function customer_tariff_percent(int $bp): string {
    return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
}

/** Basis points from a percentage typed in a form ('120', '۸۷٫۵'), or null when it is not one. */
function customer_tariff_bp_from_input(string $raw): ?int {
    $raw = str_replace(['٫', '/', ','], '.', trim(from_persian_digits($raw)));
    if ($raw === '' || !preg_match('/^\d{1,4}(\.\d{1,2})?$/', $raw)) return null;
    $bp = (int)round((float)$raw * 100);
    return $bp >= CUSTOMER_TARIFF_MIN_BP && $bp <= CUSTOMER_TARIFF_MAX_BP ? $bp : null;
}

function customer_record_tariff(PDO $db, int $userId, int $tariffBp, ?int $actorId): void {
    $db->prepare('INSERT INTO ellsms_customer_tariff_history (user_id, tariff_bp, effective_from, changed_by_user_id) VALUES (?,?,UTC_TIMESTAMP(),?)')
       ->execute([$userId, $tariffBp, $actorId]);
}

/* ==========================================================================
   Creating and editing accounts
   ========================================================================== */

/**
 * Checks a parent may create a child of $type with quota $maxSubset, tariff $tariffBp and expiry
 * $expiresOn. Returns null when it may, else a reason code (customer_error_message()).
 */
function customer_child_refusal(array $parent, string $type, int $maxSubset, int $tariffBp, ?string $expiresOn, ?PDO $db = null): ?string {
    if (!isset(CUSTOMER_TYPES[$type])) return 'invalid_type';
    if (!in_array($type, customer_allowed_child_types($parent), true)) {
        return customer_can_have_children($parent) ? 'child_type_not_allowed' : 'cannot_have_children';
    }
    $maxSubset = customer_child_max_subset($type, $maxSubset, $parent);
    $remaining = $parent['max_subset_limit'] - customer_used_subset((int)$parent['user_id'], $db);
    if ($remaining < $maxSubset + 1) return 'subset_limit';
    if ($parent['customer_type'] === 'reseller' && $tariffBp < customer_effective_tariff_bp((int)$parent['user_id'])) {
        return 'tariff_below_parent';
    }
    if ($expiresOn !== null && $parent['expires_on'] !== null && $expiresOn > $parent['expires_on']) {
        return 'expiry_after_parent';
    }
    return null;
}

/**
 * Records a brand-new account (already created on the backend) as $parentId's child. Re-checks every
 * rule under a lock on the parent's row, so two concurrent creations cannot both use the last slot
 * of the quota.
 *
 * @return array{ok:bool, reason?:string}
 */
function customer_attach_child(int $parentId, int $childId, string $type, int $maxSubset, int $tariffBp, ?string $expiresOn, int $actorId): array {
    return db_transaction(function (PDO $db) use ($parentId, $childId, $type, $maxSubset, $tariffBp, $expiresOn, $actorId): array {
        $parent = customer_account($parentId, $db, true);
        if (customer_account($childId, $db)['exists']) {
            return ['ok' => false, 'reason' => 'already_attached'];
        }
        if ($type === 'organ' && $parent['customer_type'] === 'organ') {
            // A sub-organization pays its parent's tariff and, by default, expires with it.
            $tariffBp = $parent['tariff_bp'];
            $expiresOn ??= $parent['expires_on'];
        }
        $refusal = customer_child_refusal($parent, $type, $maxSubset, $tariffBp, $expiresOn, $db);
        if ($refusal !== null) {
            return ['ok' => false, 'reason' => $refusal];
        }
        $db->prepare('INSERT INTO ellsms_customer_accounts
                        (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, expires_on, created_by_user_id)
                      VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$childId, $type, $parentId, $parent['level'] + 1, customer_child_max_subset($type, $maxSubset, $parent), $tariffBp, $expiresOn, $actorId]);
        customer_record_tariff($db, $childId, $tariffBp, $actorId);
        audit($actorId, 'customer.child_created', "parent=#{$parentId} child=#{$childId} type={$type} tariff={$tariffBp}");
        menu_access_reset_cache();
        return ['ok' => true];
    });
}

/**
 * A parent's own edits to a direct child: tariff, expiry, quota, suspension. Each key in $changes is
 * optional. Every rule a creation obeys is re-checked here.
 *
 * @return array{ok:bool, reason?:string}
 */
function customer_parent_update_child(int $parentId, int $childId, array $changes, int $actorId): array {
    return db_transaction(function (PDO $db) use ($parentId, $childId, $changes, $actorId): array {
        $parent = customer_account($parentId, $db, true);
        $child  = customer_account($childId, $db, true);
        if (!$child['exists'] || $child['parent_user_id'] !== $parentId) {
            return ['ok' => false, 'reason' => 'not_your_child'];
        }
        $subOrgan = $child['customer_type'] === 'organ' && $parent['customer_type'] === 'organ';
        $sets = [];
        $params = [];

        if (array_key_exists('tariff_bp', $changes) && !$subOrgan) {
            $bp = (int)$changes['tariff_bp'];
            if ($bp < CUSTOMER_TARIFF_MIN_BP || $bp > CUSTOMER_TARIFF_MAX_BP) return ['ok' => false, 'reason' => 'invalid_tariff'];
            if ($parent['customer_type'] === 'reseller' && $bp < customer_effective_tariff_bp($parentId)) {
                return ['ok' => false, 'reason' => 'tariff_below_parent'];
            }
            if ($bp !== $child['tariff_bp']) {
                // A sub-reseller's children may not end up charged below what it now pays.
                if ($child['customer_type'] === 'reseller' && customer_children_below_tariff($childId, $bp, $db)) {
                    return ['ok' => false, 'reason' => 'children_below_tariff'];
                }
                $sets[] = 'tariff_bp = ?';
                $params[] = $bp;
                customer_record_tariff($db, $childId, $bp, $actorId);
            }
        }
        if (array_key_exists('expires_on', $changes)) {
            $expiresOn = $changes['expires_on'];
            if ($expiresOn !== null && $parent['expires_on'] !== null && $expiresOn > $parent['expires_on']) {
                return ['ok' => false, 'reason' => 'expiry_after_parent'];
            }
            $sets[] = 'expires_on = ?';
            $params[] = $expiresOn;
        }
        if (array_key_exists('max_subset_limit', $changes)) {
            $max = customer_child_max_subset($child['customer_type'], (int)$changes['max_subset_limit'], $parent);
            $remaining = $parent['max_subset_limit'] - customer_used_subset($parentId, $db) + $child['max_subset_limit'];
            if ($remaining < $max) return ['ok' => false, 'reason' => 'subset_limit'];
            if ($max < customer_used_subset($childId, $db)) return ['ok' => false, 'reason' => 'subset_below_used'];
            $sets[] = 'max_subset_limit = ?';
            $params[] = $max;
        }
        if (array_key_exists('suspended', $changes)) {
            $sets[] = 'suspended = ?';
            $params[] = $changes['suspended'] ? 1 : 0;
        }
        if ($sets) {
            $params[] = $childId;
            $db->prepare('UPDATE ellsms_customer_accounts SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($params);
            audit($actorId, 'customer.child_updated', "parent=#{$parentId} child=#{$childId} " . json_encode($changes, JSON_UNESCAPED_UNICODE));
        }
        return ['ok' => true];
    });
}

/** Whether any reseller-set child of $userId is charged below $tariffBp. Sub-organizations follow their parent. */
function customer_children_below_tariff(int $userId, int $tariffBp, ?PDO $db = null): bool {
    $st = ($db ?? db())->prepare("SELECT COUNT(*) FROM ellsms_customer_accounts c
                                  WHERE c.parent_user_id = ? AND c.tariff_bp < ?");
    $st->execute([$userId, $tariffBp]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * A platform admin's edit of any account's place in the hierarchy. Structural rules (allowed child
 * types, no cycles, a type with children cannot become one that may not have them) are enforced;
 * the parent's quota and tariff floor are not, since setting those is exactly the admin's job.
 *
 * $input: customer_type, parent_user_id (0/null for top level), max_subset_limit, tariff_bp,
 * expires_on ('Y-m-d' or null), suspended.
 *
 * @return array{ok:bool, reason?:string}
 */
function customer_admin_save(int $userId, array $input, int $actorId): array {
    if (!customer_hierarchy_available()) {
        return ['ok' => false, 'reason' => 'not_migrated'];
    }
    $type = (string)($input['customer_type'] ?? 'normal');
    if (!isset(CUSTOMER_TYPES[$type])) return ['ok' => false, 'reason' => 'invalid_type'];
    $parentId = (int)($input['parent_user_id'] ?? 0) ?: null;
    $tariffBp = (int)($input['tariff_bp'] ?? CUSTOMER_TARIFF_BASE_BP);
    if ($tariffBp < CUSTOMER_TARIFF_MIN_BP || $tariffBp > CUSTOMER_TARIFF_MAX_BP) return ['ok' => false, 'reason' => 'invalid_tariff'];

    return db_transaction(function (PDO $db) use ($userId, $type, $parentId, $tariffBp, $input, $actorId): array {
        $current = customer_account($userId, $db, true);
        $level = 0;
        $parent = null;
        if ($parentId !== null) {
            if ($parentId === $userId || in_array($userId, customer_ancestor_ids($parentId), true)) {
                return ['ok' => false, 'reason' => 'cycle'];
            }
            $parent = customer_account($parentId, $db);
            if (!in_array($type, customer_allowed_child_types($parent), true)) {
                return ['ok' => false, 'reason' => customer_can_have_children($parent) ? 'child_type_not_allowed' : 'cannot_have_children'];
            }
            $level = $parent['level'] + 1;
        }
        $maxSubset = $parent !== null
            ? customer_child_max_subset($type, (int)($input['max_subset_limit'] ?? 0), $parent)
            : ($type === 'normal' ? 0 : max(0, (int)($input['max_subset_limit'] ?? 0)));

        // An account with children must stay a type that may have them.
        $childCount = count(customer_child_ids($userId));
        if ($childCount > 0) {
            $probe = ['customer_type' => $type, 'level' => $level, 'parent_user_id' => $parentId] + $current;
            if (!customer_can_have_children($probe) && !($type === 'organ' && $parent === null)) {
                return ['ok' => false, 'reason' => 'has_children'];
            }
        }
        if ($type === 'organ' && $parent !== null && $parent['customer_type'] === 'organ') {
            $tariffBp = $parent['tariff_bp'];
        }

        $expiresOn = $input['expires_on'] ?? null;
        $suspended = !empty($input['suspended']) ? 1 : 0;
        $db->prepare('INSERT INTO ellsms_customer_accounts
                        (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, expires_on, suspended, created_by_user_id)
                      VALUES (?,?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE customer_type = VALUES(customer_type), parent_user_id = VALUES(parent_user_id),
                        level = VALUES(level), max_subset_limit = VALUES(max_subset_limit), tariff_bp = VALUES(tariff_bp),
                        expires_on = VALUES(expires_on), suspended = VALUES(suspended)')
           ->execute([$userId, $type, $parentId, $level, $maxSubset, $tariffBp, $expiresOn, $suspended, $actorId]);
        if (!$current['exists'] || $current['tariff_bp'] !== $tariffBp) {
            customer_record_tariff($db, $userId, $tariffBp, $actorId);
        }
        // Children's levels follow a move.
        if ($current['level'] !== $level) {
            customer_relevel_children($db, $userId, $level);
        }
        menu_access_reset_cache();
        audit($actorId, 'customer.admin_saved', "#{$userId} type={$type} parent=" . ($parentId ?? '-') . " tariff={$tariffBp} max={$maxSubset} expires=" . ($expiresOn ?? '-') . " suspended={$suspended}");
        return ['ok' => true];
    });
}

function customer_relevel_children(PDO $db, int $userId, int $level, int $depth = 0): void {
    if ($depth >= CUSTOMER_MAX_DEPTH) return;
    $st = $db->prepare('SELECT user_id FROM ellsms_customer_accounts WHERE parent_user_id = ?');
    $st->execute([$userId]);
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $childId) {
        $db->prepare('UPDATE ellsms_customer_accounts SET level = ? WHERE user_id = ?')->execute([$level + 1, $childId]);
        customer_relevel_children($db, $childId, $level + 1, $depth + 1);
    }
}

/**
 * Creates a sub-account end to end: the backend account (POST /api/users/, in the parent's domain),
 * panel access, its default organization, then its place under the parent. Every hierarchy rule is
 * checked BEFORE the backend account is created, so a refused request leaves nothing behind.
 *
 * @return array{ok:bool, reason?:string, message?:string, user_id?:int}
 */
function customer_create_sub_account(array $parentUser, array $input, ?int $actorId = null): array {
    if (!customer_hierarchy_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $parentId = (int)$parentUser['id'];
    $actorId ??= $parentId; // a reseller's staff account acts for the reseller (app/ResellerPanel.php)
    $parent = customer_account($parentId);
    $type = (string)($input['customer_type'] ?? 'normal');
    $maxSubset = max(0, (int)from_persian_digits((string)($input['max_subset_limit'] ?? '0')));
    // Prices come from named tariffs (app/ResellerTariffs.php); the percentage coefficient is no
    // longer set from the panel and stays at 100% (a sub-organization keeps its parent's).
    $tariffBp = $type === 'organ' && $parent['customer_type'] === 'organ' ? $parent['tariff_bp'] : CUSTOMER_TARIFF_BASE_BP;
    // A reseller's customer must be put on one of the reseller's named tariffs.
    $tariffId = (int)($input['tariff_id'] ?? 0);
    if ($parent['customer_type'] === 'reseller' && function_exists('reseller_panel_available') && reseller_panel_available()) {
        if (reseller_tariffs_for($parentId) === []) return ['ok' => false, 'reason' => 'no_tariffs'];
        if ($tariffId <= 0 || reseller_tariff_owned($parentId, $tariffId) === null) return ['ok' => false, 'reason' => 'tariff_required'];
    }
    $expiresOn = $input['expires_on'] ?? null;
    $refusal = customer_child_refusal($parent, $type, $maxSubset, $tariffBp, $expiresOn);
    if ($refusal !== null) return ['ok' => false, 'reason' => $refusal];

    $username   = trim((string)($input['username'] ?? ''));
    $password   = (string)($input['password'] ?? '');
    $firstName  = trim((string)($input['first_name'] ?? ''));
    $lastName   = trim((string)($input['last_name'] ?? ''));
    $email      = trim((string)($input['email'] ?? ''));
    $mobile     = normalize_msisdn((string)($input['mobile'] ?? ''));
    $nationalId = trim(from_persian_digits((string)($input['national_id'] ?? '')));
    if ($username === '' || strlen($password) < 6 || $firstName === '' || $lastName === ''
        || $email === '' || !$mobile || strlen($nationalId) !== 10) {
        return ['ok' => false, 'reason' => 'fields_required'];
    }
    $domainId = backend_user_domain_id($parentId) ?? (int)(backend_list_domains()[0]['id'] ?? 0);
    if ($domainId <= 0) return ['ok' => false, 'reason' => 'no_domain'];

    [$ok, $info, $created] = backend_create_account([
        'username'          => $username,
        'password'          => $password,
        'first_name'        => $firstName,
        'last_name'         => $lastName,
        'email'             => $email,
        'mobile'            => (int)$mobile,
        'national_id'       => $nationalId,
        'domain_id'         => $domainId,
        'gender'            => ($input['gender'] ?? 'MALE') === 'FEMALE' ? 'FEMALE' : 'MALE',
        'code'              => (string)random_int(10000000, 99999999),
        'daily_limit'       => 1000,
        'min_credit_notify' => 0,
        'limit_time_from'   => '00:00',
        'limit_time_to'     => '23:59',
    ]);
    if (!$ok || !$created || empty($created['id'])) {
        return ['ok' => false, 'reason' => 'backend_failed', 'message' => (string)$info];
    }
    $childId = (int)$created['id'];
    $accountType = $type === 'organ' || ($input['account_type'] ?? '') === 'legal' ? 'legal' : 'individual';
    db_transaction(function () use ($childId, $username, $firstName, $lastName, $accountType, $parentId): void {
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?)
                       ON DUPLICATE KEY UPDATE panel_access = 1')
           ->execute([$childId, '']); // no sender until a line is assigned (system_default_originator())
        $displayName = trim($firstName . ' ' . $lastName) ?: $username;
        $org = ensure_user_has_organization($childId, $displayName . "'s Workspace");
        if ($org['ok'] && $org['organization_id']) {
            profile_organization_save($org['organization_id'], ['account_type' => $accountType], $parentId);
        }
    });
    $attached = customer_attach_child($parentId, $childId, $type, $maxSubset, $tariffBp, $expiresOn, $actorId);
    if (!$attached['ok']) {
        // Lost a race for the last quota slot between the check above and the lock: the backend
        // account exists but must not stay usable outside the hierarchy.
        db()->prepare('UPDATE ellsms_meta SET panel_access = 0 WHERE user_id = ?')->execute([$childId]);
        Logger::warning('customer.child_attach_failed', ['parent_id' => $parentId, 'child_id' => $childId, 'reason' => $attached['reason']]);
        return $attached;
    }
    // The named tariff chosen at creation (app/ResellerTariffs.php).
    if ($tariffId > 0 && function_exists('reseller_assign_tariff')) {
        reseller_assign_tariff($parentId, $childId, $tariffId, $actorId);
    }
    audit($actorId, 'customer.sub_account_created', "#{$childId} {$username} type={$type} parent=#{$parentId}");
    return ['ok' => true, 'user_id' => $childId];
}

/* ==========================================================================
   Sending lines a parent shares with its sub-accounts
   ========================================================================== */

/** Lines the parent owns outright or through its organization — the ones it may share. */
function customer_parent_lines(array $parentUser): array {
    $organizationId = (int)($parentUser['organization_id'] ?? 0);
    $st = db()->prepare('SELECT id, number, label FROM ellsms_numbers WHERE assigned_user_id = ? OR (organization_id IS NOT NULL AND organization_id = ?) ORDER BY number');
    $st->execute([(int)$parentUser['id'], $organizationId]);
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'number' => (string)$r['number'], 'label' => (string)$r['label']], $st->fetchAll());
}

/** @return list<int> ids of the parent's lines currently shared with the child */
function customer_child_line_ids(array $parentUser, int $childId): array {
    $ids = array_column(customer_parent_lines($parentUser), 'id');
    if (!$ids) return [];
    $st = db()->prepare('SELECT number_id FROM ellsms_number_shares WHERE user_id = ? AND number_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([$childId], $ids));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Replaces which of the parent's lines the child may send from (send-only shares). */
function customer_set_child_lines(array $parentUser, int $childId, array $lineIds, int $actorId): array {
    $child = customer_account($childId);
    if (!$child['exists'] || $child['parent_user_id'] !== (int)$parentUser['id']) return ['ok' => false, 'reason' => 'not_your_child'];
    $own = array_column(customer_parent_lines($parentUser), 'id');
    $wanted = array_values(array_intersect($own, array_map('intval', $lineIds)));
    db_transaction(function (PDO $db) use ($own, $wanted, $childId, $actorId): void {
        if ($own) {
            $db->prepare('DELETE FROM ellsms_number_shares WHERE user_id = ? AND number_id IN (' . implode(',', array_fill(0, count($own), '?')) . ')')
               ->execute(array_merge([$childId], $own));
        }
        $ins = $db->prepare('INSERT IGNORE INTO ellsms_number_shares (number_id, user_id, created_by) VALUES (?,?,?)');
        foreach ($wanted as $id) $ins->execute([$id, $childId, $actorId]);
    });
    audit($actorId, 'customer.child_lines', "child=#{$childId} lines=" . implode(',', $wanted));
    return ['ok' => true];
}

/* ==========================================================================
   Credit transfer
   ========================================================================== */

/**
 * Moves credit between a parent and its direct child. $direction 'give' moves parent -> child,
 * 'take' moves child -> parent (Negar's «بازگرداندن اعتبار»). Never overdraws either side.
 *
 * @return array{ok:bool, reason?:string, replayed?:bool}
 */
function customer_transfer_credit(int $parentId, int $childId, int $amount, string $direction, int $actorId, string $note, string $operationId): array {
    if ($amount <= 0) return ['ok' => false, 'reason' => 'invalid_amount'];
    if (!in_array($direction, ['give', 'take'], true)) return ['ok' => false, 'reason' => 'invalid_direction'];
    $child = customer_account($childId);
    if (!$child['exists'] || $child['parent_user_id'] !== $parentId) {
        return ['ok' => false, 'reason' => 'not_your_child'];
    }
    [$from, $to] = $direction === 'give' ? [$parentId, $childId] : [$childId, $parentId];
    $result = wallet_transfer($from, $to, $amount, 'customer_credit_transfer', "{$parentId}:{$childId}",
        'credit_transfer:' . $operationId, $actorId, ['direction' => $direction, 'parent' => $parentId, 'child' => $childId, 'note' => mb_substr(trim($note), 0, 190)]);
    if ($result['ok'] && empty($result['replayed'])) {
        audit($actorId, 'customer.credit_' . $direction, "parent=#{$parentId} child=#{$childId} amount={$amount}");
    }
    return $result;
}

/* ==========================================================================
   Reseller profit
   ========================================================================== */

/**
 * The tariff coefficient that applied to $userId at $atUtc, from the change history (the current
 * value when the history has nothing that early). A sub-organization's is its parent's.
 *
 * @param array<int,list<array{0:string,1:int}>> $history user_id => [[effective_from, bp], ...] ascending
 * @param array<int,array> $accounts user_id => account
 */
function customer_tariff_at(int $userId, string $atUtc, array $history, array $accounts): int {
    $account = $accounts[$userId] ?? customer_account_defaults($userId);
    if ($account['customer_type'] === 'organ' && $account['parent_user_id'] !== null) {
        $parent = $accounts[(int)$account['parent_user_id']] ?? null;
        if ($parent !== null && $parent['customer_type'] === 'organ') {
            return customer_tariff_at((int)$account['parent_user_id'], $atUtc, $history, $accounts);
        }
    }
    $bp = null;
    foreach ($history[$userId] ?? [] as [$from, $value]) {
        if ($from <= $atUtc) {
            $bp = $value;
        } else {
            break;
        }
    }
    if ($bp === null) {
        // Nothing recorded yet at that instant: the earliest recorded value if any (the account was
        // charged at it from creation), else the current one.
        $bp = isset($history[$userId][0]) ? $history[$userId][0][1] : $account['tariff_bp'];
    }
    return $bp;
}

/**
 * Pure profit arithmetic for one charge, so it can be tested without a database.
 *
 * $chain is the spender followed by its ancestors, each as ['user_id', 'type', 'bp']. The spender's
 * charge $amount was priced at chain[0]'s bp, so the list price was $amount / bp. Every reseller
 * up the chain earns (bp of the account just below it on the chain - its own bp) of the list price.
 * Negative differences (a reseller whose own tariff was later raised above a child's) pay nothing.
 *
 * @return array<int,float> reseller user id => profit (unrounded)
 */
function customer_profit_split(int $amount, array $chain): array {
    if ($amount <= 0 || count($chain) < 2 || $chain[0]['bp'] <= 0) return [];
    $listPrice = $amount / $chain[0]['bp'];
    $out = [];
    for ($i = 1, $n = count($chain); $i < $n; $i++) {
        if ($chain[$i]['type'] !== 'reseller') continue;
        $diff = $chain[$i - 1]['bp'] - $chain[$i]['bp'];
        if ($diff > 0) {
            $out[$chain[$i]['user_id']] = ($out[$chain[$i]['user_id']] ?? 0.0) + $listPrice * $diff;
        }
    }
    return $out;
}

/**
 * Pure: a charge of $amount spread over a send's price-snapshot groups, each group's share in
 * proportion to its accepted cost, and each share split by that group's chain prices.
 *
 * @param list<array{cost:int, chain:list<array{0:int,1:string,2:?int}>}> $groups
 * @return array<int,float> reseller user id => profit (unrounded)
 */
function customer_profit_split_by_groups(int $amount, array $groups): array {
    $total = array_sum(array_map(static fn(array $g): int => max(0, (int)$g['cost']), $groups));
    if ($amount <= 0 || $total <= 0) return [];
    $out = [];
    foreach ($groups as $group) {
        $share = $amount * max(0, (int)$group['cost']) / $total;
        $chain = [];
        foreach ($group['chain'] as [$userId, $type, $price]) {
            if ($price === null) break;
            $chain[] = ['user_id' => (int)$userId, 'type' => (string)$type, 'bp' => (int)$price];
        }
        foreach (customer_profit_split((int)round($share * 1000), $chain) as $resellerId => $profit) {
            $out[$resellerId] = ($out[$resellerId] ?? 0.0) + $profit / 1000;
        }
    }
    return $out;
}

/**
 * The chain-priced snapshot groups of one send by $userId: [{cost, chain}], empty when the send has
 * none (no snapshot, no parent, or a snapshot from before chain prices were recorded).
 */
function customer_profit_snapshot_groups(string $referenceType, string $referenceId, int $userId): array {
    if (!function_exists('sms_price_snapshot_has_chain_column') || !sms_price_snapshot_has_chain_column()) return [];
    $st = db()->prepare('SELECT total_cost_credits, chain_prices FROM ellsms_sms_price_snapshots
                         WHERE reference_type = ? AND reference_id = ? AND user_id = ? AND chain_prices IS NOT NULL');
    $st->execute([$referenceType, $referenceId, $userId]);
    $groups = [];
    foreach ($st as $row) {
        $chain = json_decode((string)$row['chain_prices'], true);
        if (is_array($chain) && count($chain) >= 2) {
            $groups[] = ['cost' => (int)$row['total_cost_credits'], 'chain' => $chain];
        }
    }
    return $groups;
}

/**
 * Pays resellers their profit for every finished Tehran-local day not yet processed, up to
 * yesterday (counting a day as finished an hour after it ends). Each day is one transaction: the per-source rows, one wallet credit per reseller
 * (idempotency key reseller_profit:<day>:<reseller>) and the day's completion marker. Safe to run
 * repeatedly and concurrently with itself: a processed day is skipped, and a replayed credit is a
 * no-op in the wallet.
 *
 * @return array{days:list<string>, paid:int, total:int}
 */
function customer_profit_run(bool $dryRun = false, ?string $today = null): array {
    $summary = ['days' => [], 'paid' => 0, 'total' => 0];
    if (!customer_hierarchy_available()) return $summary;

    // A day is processed only once it has been over for an hour, so a charge written at 23:59:59
    // by a transaction that commits a moment later is never missed.
    $today ??= (new DateTimeImmutable('-1 hour', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $last = db()->query('SELECT MAX(profit_day) FROM ellsms_reseller_profit_days')->fetchColumn();
    if ($last) {
        $day = (new DateTimeImmutable((string)$last))->modify('+1 day')->format('Y-m-d');
    } else {
        $first = db()->query('SELECT MIN(created_at) FROM ellsms_customer_accounts WHERE parent_user_id IS NOT NULL')->fetchColumn();
        if (!$first) return $summary;
        $day = (new DateTimeImmutable((string)$first, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        $earliest = (new DateTimeImmutable($today))->modify('-' . CUSTOMER_PROFIT_BACKFILL_DAYS . ' days')->format('Y-m-d');
        if ($day < $earliest) $day = $earliest;
    }
    while ($day <= $yesterday) {
        $result = customer_profit_run_day($day, $dryRun);
        $summary['days'][] = $day;
        $summary['paid'] += $result['paid'];
        $summary['total'] += $result['total'];
        $day = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }
    return $summary;
}

/** @return array{paid:int, total:int, rows:list<array>} */
function customer_profit_run_day(string $day, bool $dryRun = false): array {
    [$fromUtc, $toUtc] = local_day_range_to_utc($day, $day);

    // Charges by accounts that have a parent. sms_debit is the only ledger type that is a spend:
    // reservations move nothing until committed, and a release returns what was never spent.
    $st = db()->prepare("SELECT t.user_id, t.amount, t.created_at, t.reference_type, t.reference_id
                         FROM ellsms_wallet_transactions t
                         JOIN ellsms_customer_accounts c ON c.user_id = t.user_id AND c.parent_user_id IS NOT NULL
                         WHERE t.type = 'sms_debit' AND t.amount < 0 AND t.created_at >= ? AND t.created_at < ?");
    $st->execute([$fromUtc, $toUtc]);
    $charges = $st->fetchAll();

    $accounts = [];
    $history = [];
    $profits = []; // "reseller:source" => ['reseller', 'source', 'spent', 'profit']
    if ($charges) {
        $all = db()->query('SELECT * FROM ellsms_customer_accounts')->fetchAll();
        foreach ($all as $row) $accounts[(int)$row['user_id']] = customer_account_normalize($row);
        foreach (db()->query('SELECT user_id, tariff_bp, effective_from FROM ellsms_customer_tariff_history ORDER BY effective_from, id') as $row) {
            $history[(int)$row['user_id']][] = [(string)$row['effective_from'], (int)$row['tariff_bp']];
        }
    }
    $snapshotChains = [];
    foreach ($charges as $charge) {
        $sourceId = (int)$charge['user_id'];
        $amount = -(int)$charge['amount'];
        // A send priced down the chain (app/ResellerTariffs.php) recorded what every account on it
        // paid; profit follows those exact prices. Anything else (older sends, voice, Bale) is
        // split by the tariff percentages in force at the time of the charge.
        $refKey = $charge['reference_type'] . "\0" . $charge['reference_id'] . "\0" . $sourceId;
        $snapshotChains[$refKey] ??= customer_profit_snapshot_groups((string)$charge['reference_type'], (string)$charge['reference_id'], $sourceId);
        if ($snapshotChains[$refKey] !== []) {
            $split = customer_profit_split_by_groups($amount, $snapshotChains[$refKey]);
        } else {
            $chain = [];
            $current = $sourceId;
            $seen = [];
            while ($current !== null && isset($accounts[$current]) && count($chain) <= CUSTOMER_MAX_DEPTH && !isset($seen[$current])) {
                $seen[$current] = true;
                $chain[] = ['user_id' => $current, 'type' => $accounts[$current]['customer_type'], 'bp' => customer_tariff_at($current, (string)$charge['created_at'], $history, $accounts)];
                $current = $accounts[$current]['parent_user_id'];
            }
            $split = customer_profit_split($amount, $chain);
        }
        foreach ($split as $resellerId => $profit) {
            $key = $resellerId . ':' . $sourceId;
            $profits[$key] ??= ['reseller' => $resellerId, 'source' => $sourceId, 'spent' => 0, 'profit' => 0.0];
            $profits[$key]['spent'] += $amount;
            $profits[$key]['profit'] += $profit;
        }
    }

    $rows = [];
    $byReseller = [];
    foreach ($profits as $p) {
        $p['profit'] = (int)floor($p['profit'] + 1e-6);
        $rows[] = $p;
        $byReseller[$p['reseller']] = ($byReseller[$p['reseller']] ?? 0) + $p['profit'];
    }
    $total = array_sum($byReseller);
    $paid = count(array_filter($byReseller, static fn(int $v): bool => $v > 0));
    if ($dryRun) {
        return ['paid' => $paid, 'total' => $total, 'rows' => $rows];
    }

    db_transaction(function (PDO $db) use ($day, $rows, $byReseller, $paid, $total): void {
        $claim = $db->prepare('INSERT IGNORE INTO ellsms_reseller_profit_days (profit_day, resellers_paid, total_profit) VALUES (?,?,?)');
        $claim->execute([$day, $paid, $total]);
        if ($claim->rowCount() === 0) {
            return; // another run finished this day first
        }
        $ins = $db->prepare('INSERT INTO ellsms_reseller_profits (profit_day, reseller_user_id, source_user_id, spent, profit) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE spent = VALUES(spent), profit = VALUES(profit)');
        foreach ($rows as $r) {
            $ins->execute([$day, $r['reseller'], $r['source'], $r['spent'], $r['profit']]);
        }
        foreach ($byReseller as $resellerId => $amount) {
            if ($amount > 0) {
                wallet_credit((int)$resellerId, $amount, 'reseller_profit', 'reseller_profit', $day, "reseller_profit:{$day}:{$resellerId}", null, ['day' => $day]);
            }
        }
    });
    Logger::info('customer.profit_day_processed', ['day' => $day, 'resellers_paid' => $paid, 'total' => $total]);
    return ['paid' => $paid, 'total' => $total, 'rows' => $rows];
}

/* ==========================================================================
   Messages
   ========================================================================== */

function customer_error_message(string $reason): string {
    return [
        'invalid_type'           => 'نوع حساب نامعتبر است.',
        'cannot_have_children'   => 'این حساب نمی‌تواند زیرمجموعه داشته باشد.',
        'child_type_not_allowed' => 'این نوع زیرمجموعه برای این حساب مجاز نیست (زیرنماینده فقط مشتری عادی و سازمانی می‌سازد و سازمان فقط زیرسازمان).',
        'subset_limit'           => 'سقف تعداد زیرمجموعه‌ی شما برای این کار کافی نیست.',
        'subset_below_used'      => 'سقف زیرمجموعه نمی‌تواند کمتر از مقداری باشد که زیرمجموعه‌های فعلی این حساب استفاده کرده‌اند.',
        'tariff_below_parent'    => 'تعرفه‌ی زیرمجموعه نمی‌تواند کمتر از تعرفه‌ی خودتان باشد.',
        'children_below_tariff'  => 'برخی از زیرمجموعه‌های این حساب تعرفه‌ای کمتر از این مقدار دارند؛ ابتدا تعرفه‌ی آن‌ها را اصلاح کنید.',
        'expiry_after_parent'    => 'تاریخ انقضای زیرمجموعه نمی‌تواند بعد از تاریخ انقضای حساب خودتان باشد.',
        'invalid_tariff'         => 'تعرفه باید درصدی بین ۱۰ و ۱۰۰۰ باشد.',
        'tariff_required'        => 'یکی از تعرفه‌هایتان را برای این مشتری انتخاب کنید.',
        'no_tariffs'             => 'ابتدا در «تعرفه‌ها» حداقل یک تعرفه برای مشتریانتان تعریف کنید.',
        'not_your_child'         => 'این حساب زیرمجموعه‌ی شما نیست.',
        'already_attached'       => 'این حساب از قبل در ساختار نمایندگی ثبت شده است.',
        'cycle'                  => 'یک حساب نمی‌تواند زیرمجموعه‌ی خودش یا زیرمجموعه‌هایش باشد.',
        'has_children'           => 'این حساب زیرمجموعه دارد و نمی‌تواند به نوعی تغییر کند که زیرمجموعه نمی‌پذیرد.',
        'invalid_amount'         => 'مبلغ نامعتبر است.',
        'invalid_direction'      => 'عملیات نامعتبر است.',
        'insufficient_balance'   => 'اعتبار کافی نیست.',
        'fields_required'        => 'همه‌ی فیلدهای ستاره‌دار را به‌درستی پر کنید (رمز عبور حداقل ۶ نویسه و کد ملی دقیقاً ۱۰ رقم).',
        'no_domain'              => 'دامنه‌ای برای ساخت حساب پیدا نشد؛ با پشتیبانی تماس بگیرید.',
        'backend_failed'         => 'ساخت حساب در سامانه‌ی مرکزی ناموفق بود.',
        'not_migrated'           => 'جدول‌های نمایندگی هنوز در پایگاه داده ساخته نشده‌اند (مهاجرت 2026_10_12_customer_hierarchy.sql).',
    ][$reason] ?? 'عملیات انجام نشد.';
}
