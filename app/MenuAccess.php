<?php
/**
 * ELLSMS — which panel menus a user may see and open.
 *
 * A menu role (ellsms_menu_roles) is a named set of menu keys from menu_catalog(). A user may be
 * given one role plus per-user exceptions: 'allow' adds a menu the role lacks, 'deny' removes one.
 * A user with neither sees every menu, exactly as before this feature existed.
 *
 * A sub-account can never see more than its parent: its effective menus are always intersected with
 * its parent's (and so on up the chain). Platform admins set any user's access; a reseller or
 * organization sets its own sub-accounts', and only from menus it has itself.
 *
 * A closed menu is hidden in the sidebar (app/views/header.php) AND its pages answer 403
 * (menu_access_enforce(), called from require_login()), and the public API refuses the matching
 * routes for keys created by that user (menu_access_api_refusal()). Platform admins are never
 * restricted. Dashboard, notifications, guide, onboarding and profile are not in the catalog and
 * are always available.
 *
 * Schema: db/migrations/2026_10_12_customer_hierarchy.sql. Until it is applied nothing is restricted.
 */

declare(strict_types=1);

/**
 * key => [label, icon, href, pages that belong to it]. A page listed under several keys opens when
 * any one of them is allowed (the import wizard serves three send pages).
 */
function menu_catalog(): array {
    return [
        'new_send'      => ['پنل جدید ارسال',       '🆕', '/new-send.php',      ['new-send.php', 'send-estimate.php', 'import.php', 'import-status.php', 'import-confirm.php', 'import-cancel.php']],
        'send'          => ['ارسال پیامک',          '➤',  '/send.php',          ['send.php', 'send-queue.php', 'send-estimate.php']],
        'p2p'           => ['نظیر به نظیر',         '⇄',  '/p2p-send.php',      ['p2p-send.php', 'import.php', 'import-status.php', 'import-confirm.php', 'import-cancel.php']],
        'smart'         => ['پیامک هوشمند',         '✦',  '/smart-send.php',    ['smart-send.php', 'import.php', 'import-status.php', 'import-confirm.php', 'import-cancel.php']],
        'schedules'     => ['زمان‌بندی‌شده',         '◷',  '/schedules.php',     ['schedules.php']],
        'autoreply'     => ['منشی پیامک',           '🤖', '/autoreply.php',     ['autoreply.php']],
        'reports'       => ['گزارش ارسال',          '≣',  '/reports.php',       ['reports.php', 'reports-v2.php', 'message-detail.php', 'report-exports.php', 'bulk-job.php', 'operator-reports.php']],
        'reports_bulk'  => ['گزارش ارسال حجیم',     '≣',  '/reports-bulk.php',  ['reports-bulk.php', 'bulk-job.php']],
        'inbox'         => ['صندوق دریافت',         '✉',  '/inbox.php',         ['inbox.php']],
        'contacts'      => ['مخاطبین',              '☰',  '/contacts.php',      ['contacts.php']],
        'blacklist'     => ['لیست سیاه',            '🚫', '/blacklist.php',     ['blacklist.php']],
        'line_optouts'  => ['لغو عضویت (۱۱)',       '⛔', '/line-optouts.php',  ['line-optouts.php']],
        'tickets'       => ['پشتیبانی',             '🎫', '/tickets.php',       ['tickets.php']],
        'buy_credit'    => ['خرید اعتبار',          '💳', '/buy-credit.php',    ['buy-credit.php', 'packages.php']],
        'invoices'      => ['فاکتورها',             '🧾', '/invoices.php',      ['invoices.php']],
        'financial_log' => ['تاریخچه مالی',         '📒', '/financial-log.php', ['financial-log.php']],
        'bale'          => ['پیام‌رسان بله',         '💬', '/bale-messages.php', ['bale-messages.php']],
        'voice'         => ['پیام صوتی',            '🔊', '/voice.php',         ['voice.php']],
        'voice_files'   => ['فایل‌های صوتی',         '🎙', '/voice-files.php',   ['voice-files.php']],
        'regional_bulk' => ['ارسال منطقه‌ای',        '🗺️', '/regional-bulk.php', ['regional-bulk.php']],
        'aimed_bulk'    => ['ارسال با کد پستی',      '📮', '/aimed-bulk.php',    ['aimed-bulk.php']],
        'otp_templates' => ['کد تایید اشتراکی',      '🔐', '/otp-templates.php', ['otp-templates.php']],
        // A reseller's panel (app/ResellerPanel.php) is its sub-account menu: closing it closes the panel.
        'subaccounts'   => ['زیرمجموعه‌ها',          '👥', '/subaccounts.php',   ['subaccounts.php', 'reseller.php', 'reseller-customers.php', 'reseller-customer.php',
                                                                                'reseller-tariffs.php', 'reseller-packages.php', 'reseller-finance.php', 'reseller-payments.php', 'reseller-tickets.php',
                                                                                'reseller-announcements.php', 'reseller-registrations.php', 'reseller-staff.php', 'reseller-settings.php']],
        'api_keys'      => ['کلیدهای API',          '🔑', '/api-keys.php',      ['api-keys.php']],
        'integrations'  => ['افزونه ووکامرس و SDK', '🧩', '/integrations.php',  ['integrations.php']],
        'remote_db'     => ['اتصال به دیتابیس',     '🗄', '/remote-db.php',     ['remote-db.php']],
        'webhooks'      => ['وب‌هوک‌ها',              '🔗', '/webhooks.php',      ['webhooks.php']],
        'number_forwards' => ['فوروارد پیامک به URL', '📡', '/number-forwards.php', ['number-forwards.php']],
        'billing'       => ['اشتراک و مصرف',        '📦', '/billing.php',       ['billing.php']],
    ];
}

/**
 * The customer side of the sidebar, in display order: group id => [label, keys]. Covers every
 * menu_catalog() key plus the always-available pages; the menu-access forms group their rows the same
 * way. The admin-only groups live in app/views/header.php.
 */
function menu_groups(): array {
    return [
        'general'      => ['عمومی',            ['dashboard', 'notifications', 'onboarding', 'guide']],
        'send'         => ['ارسال پیامک',       ['new_send', 'send', 'p2p', 'smart', 'regional_bulk', 'aimed_bulk', 'schedules', 'autoreply']],
        'voice'        => ['پیام صوتی',         ['voice', 'voice_files']],
        'reports'      => ['گزارش و دریافت',    ['reports', 'reports_bulk', 'operator_reports', 'operator_reports_number', 'operator_reports_route', 'inbox', 'bale']],
        'contacts'     => ['مخاطبین',           ['contacts', 'blacklist', 'line_optouts']],
        'finance'      => ['مالی',              ['buy_credit', 'reseller_packages', 'invoices', 'financial_log', 'billing']],
        'integrations' => ['یکپارچه‌سازی',       ['api_keys', 'otp_templates', 'integrations', 'remote_db', 'webhooks', 'number_forwards']],
        'account'      => ['حساب و پشتیبانی',   ['reseller_panel', 'subaccounts', 'tickets']],
    ];
}

/**
 * Pure: splits $items (key => anything, already filtered for access) into $groups (id => [label, keys]),
 * keeping the group order and each group's key order. Empty groups are dropped; keys no group names
 * end up in a trailing «سایر» group so nothing silently disappears.
 *
 * @return array<string, array{0:string, 1:array}> group id => [label, key => item]
 */
function menu_group_items(array $items, array $groups): array {
    $out = [];
    foreach ($groups as $id => [$label, $keys]) {
        $members = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $items)) {
                $members[$key] = $items[$key];
                unset($items[$key]);
            }
        }
        if ($members !== []) $out[$id] = [$label, $members];
    }
    if ($items !== []) $out['other'] = ['سایر', $items];
    return $out;
}

/** @return list<string> */
function menu_keys(): array {
    return array_keys(menu_catalog());
}

function menu_label(string $key): string {
    return menu_catalog()[$key][0] ?? $key;
}

/** @return list<string> the menu keys that guard $script (a public/ file name), empty when it is not guarded */
function menu_keys_for_script(string $script): array {
    $script = basename($script);
    $keys = [];
    foreach (menu_catalog() as $key => [, , , $pages]) {
        if (in_array($script, $pages, true)) $keys[] = $key;
    }
    return $keys;
}

/* ==========================================================================
   Effective access
   ========================================================================== */

/** The user's own role and exceptions, before the parent chain is applied. */
function menu_user_access_get(int $userId): array {
    $out = ['role_id' => null, 'overrides' => []];
    if ($userId <= 0 || !customer_hierarchy_available()) return $out;
    $st = db()->prepare('SELECT role_id FROM ellsms_user_menu_access WHERE user_id = ?');
    $st->execute([$userId]);
    $roleId = $st->fetchColumn();
    $out['role_id'] = $roleId !== false && $roleId !== null ? (int)$roleId : null;
    $st = db()->prepare('SELECT menu_key, mode FROM ellsms_user_menu_overrides WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $row) {
        $out['overrides'][(string)$row['menu_key']] = (string)$row['mode'];
    }
    return $out;
}

/** @return list<string> */
function menu_role_keys(int $roleId): array {
    $st = db()->prepare('SELECT menu_key FROM ellsms_menu_role_items WHERE role_id = ? ORDER BY menu_key');
    $st->execute([$roleId]);
    return array_values(array_intersect(menu_keys(), $st->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Pure: the user's own menu set from a role's keys (null = no role) and its exceptions. Null means
 * "no restriction of its own".
 *
 * @param list<string>|null $roleKeys
 * @param array<string,string> $overrides
 * @return list<string>|null
 */
function menu_access_own_set(?array $roleKeys, array $overrides): ?array {
    if ($roleKeys === null && $overrides === []) return null;
    $set = array_fill_keys($roleKeys ?? menu_keys(), true);
    foreach ($overrides as $key => $mode) {
        if ($mode === 'allow') $set[$key] = true;
        if ($mode === 'deny') unset($set[$key]);
    }
    return array_values(array_intersect(menu_keys(), array_keys($set)));
}

/** Pure: intersects two menu sets where null means "everything". */
function menu_access_intersect(?array $a, ?array $b): ?array {
    if ($a === null) return $b;
    if ($b === null) return $a;
    return array_values(array_intersect($a, $b));
}

/**
 * The menus $userId may use: its own set intersected with every ancestor's. Null = every menu.
 *
 * @return list<string>|null
 */
function menu_access_effective(int $userId): ?array {
    if ($userId <= 0 || !customer_hierarchy_available()) return null;
    $cache = &menu_access_cache();
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    $result = null;
    foreach (array_merge([$userId], customer_ancestor_ids($userId)) as $id) {
        $access = menu_user_access_get($id);
        $roleKeys = $access['role_id'] !== null ? menu_role_keys($access['role_id']) : null;
        $result = menu_access_intersect($result, menu_access_own_set($roleKeys, $access['overrides']));
    }
    // Bounded: a request touches a handful of users at most, but workers are long-lived.
    if (count($cache) > 500) $cache = [];
    $cache[$userId] = $result;
    return $result;
}

/** Per-process cache of menu_access_effective(), keyed by user id. */
function &menu_access_cache(): array {
    static $cache = [];
    return $cache;
}

/** Called after any change to roles or a user's access, so the rest of the request sees it. */
function menu_access_reset_cache(): void {
    $cache = &menu_access_cache();
    $cache = [];
}

/** Whether $user may use menu $key. Admins and keys outside the catalog are always allowed. */
function menu_access_allows(array $user, string $key): bool {
    if (($user['role'] ?? null) === 'admin' || !isset(menu_catalog()[$key])) return true;
    $set = menu_access_effective((int)($user['id'] ?? 0));
    return $set === null || in_array($key, $set, true);
}

/**
 * 403 for a page whose menu the user may not open. Called from require_login() for every
 * authenticated page.
 */
function menu_access_enforce(array $user, ?string $script = null): void {
    if (($user['role'] ?? null) === 'admin') return;
    $script ??= (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '');
    $keys = menu_keys_for_script($script);
    if ($keys === []) return;
    foreach ($keys as $key) {
        if (menu_access_allows($user, $key)) return;
    }
    Logger::info('menu_access.denied', ['user_id' => $user['id'] ?? null, 'script' => basename($script)]);
    http_response_code(403);
    exit('۴۰۳ — دسترسی به این بخش برای حساب شما فعال نیست.');
}

/**
 * The menu a public API route belongs to, for refusing an API key whose creator may not use it.
 * Null for routes that belong to no menu (/me, /organization, /balance).
 */
function menu_key_for_api_path(string $path): ?string {
    return match (true) {
        str_starts_with($path, '/api/v1/messages'),
        str_starts_with($path, '/api/v1/webengage'),
        str_starts_with($path, '/api/v1/bulk-jobs') => 'send',
        str_starts_with($path, '/api/v1/contacts')  => 'contacts',
        str_starts_with($path, '/api/v1/voice')     => 'voice',
        str_starts_with($path, '/api/v1/otp')       => 'otp_templates',
        str_starts_with($path, '/api/v1/webhooks')  => 'webhooks',
        default => null,
    };
}

/**
 * Whether an API request must be refused because the key's creator has lost the menu behind it (or
 * API keys altogether). Returns the menu key that is missing, or null when the request may proceed.
 */
function menu_access_api_refusal(int $creatorUserId, string $path): ?string {
    if ($creatorUserId <= 0 || !customer_hierarchy_available()) return null;
    $creator = backend_find_user_by_id($creatorUserId);
    if ($creator && !empty($creator['is_admin'])) return null;
    $user = ['id' => $creatorUserId, 'role' => 'user'];
    if (!menu_access_allows($user, 'api_keys')) return 'api_keys';
    $key = menu_key_for_api_path($path);
    return ($key !== null && !menu_access_allows($user, $key)) ? $key : null;
}

/* ==========================================================================
   Roles
   ========================================================================== */

/**
 * Roles a manager may assign: the admin's (owner NULL) plus, for a reseller or organization, its own.
 * $ownerUserId null = a platform admin, who sees only admin roles here.
 */
function menu_roles_for(?int $ownerUserId): array {
    if (!customer_hierarchy_available()) return [];
    if ($ownerUserId === null) {
        $st = db()->query('SELECT * FROM ellsms_menu_roles WHERE owner_user_id IS NULL ORDER BY name');
    } else {
        $st = db()->prepare('SELECT * FROM ellsms_menu_roles WHERE owner_user_id IS NULL OR owner_user_id = ? ORDER BY owner_user_id IS NULL, name');
        $st->execute([$ownerUserId]);
    }
    $roles = [];
    foreach ($st->fetchAll() as $row) {
        $row['id'] = (int)$row['id'];
        $row['owner_user_id'] = $row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null;
        $row['keys'] = menu_role_keys($row['id']);
        $roles[$row['id']] = $row;
    }
    return $roles;
}

/**
 * Creates or updates a role owned by $ownerUserId (null = admin role). A manager may only edit its
 * own roles, and a reseller's role may only contain menus the reseller has itself.
 *
 * @return array{ok:bool, reason?:string, id?:int}
 */
function menu_role_save(?int $roleId, string $name, array $keys, ?int $ownerUserId, int $actorId): array {
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') return ['ok' => false, 'reason' => 'name_required'];
    $keys = array_values(array_intersect(menu_keys(), array_map('strval', $keys)));
    if ($ownerUserId !== null) {
        $own = menu_access_effective($ownerUserId);
        if ($own !== null) $keys = array_values(array_intersect($keys, $own));
    }
    return db_transaction(function (PDO $db) use ($roleId, $name, $keys, $ownerUserId, $actorId): array {
        if ($roleId !== null) {
            $st = $db->prepare('SELECT owner_user_id FROM ellsms_menu_roles WHERE id = ? FOR UPDATE');
            $st->execute([$roleId]);
            $row = $st->fetch();
            if (!$row || ($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null) !== $ownerUserId) {
                return ['ok' => false, 'reason' => 'role_not_found'];
            }
            $db->prepare('UPDATE ellsms_menu_roles SET name = ? WHERE id = ?')->execute([$name, $roleId]);
            $db->prepare('DELETE FROM ellsms_menu_role_items WHERE role_id = ?')->execute([$roleId]);
        } else {
            $db->prepare('INSERT INTO ellsms_menu_roles (name, owner_user_id) VALUES (?, ?)')->execute([$name, $ownerUserId]);
            $roleId = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO ellsms_menu_role_items (role_id, menu_key) VALUES (?, ?)');
        foreach ($keys as $key) $ins->execute([$roleId, $key]);
        audit($actorId, 'menu_role.saved', "#{$roleId} owner=" . ($ownerUserId ?? 'admin') . ' keys=' . implode(',', $keys));
        menu_access_reset_cache();
        return ['ok' => true, 'id' => $roleId];
    });
}

/** Deletes a role its owner manages. Users who had it lose the role (and keep their exceptions). */
function menu_role_delete(int $roleId, ?int $ownerUserId, int $actorId): bool {
    $sql = 'DELETE FROM ellsms_menu_roles WHERE id = ? AND ' . ($ownerUserId === null ? 'owner_user_id IS NULL' : 'owner_user_id = ?');
    $st = db()->prepare($sql);
    $st->execute($ownerUserId === null ? [$roleId] : [$roleId, $ownerUserId]);
    if ($st->rowCount() > 0) {
        audit($actorId, 'menu_role.deleted', "#{$roleId}");
        menu_access_reset_cache();
        return true;
    }
    return false;
}

/**
 * Sets a user's role and exceptions. $managerUserId null = platform admin (any admin role). For a
 * parent, the role must be an admin role or one the parent owns, and exceptions are kept only for
 * menus the parent has, so a parent can never hand out what it does not have.
 *
 * $overrides: menu key => 'allow' | 'deny' | anything else (= follow the role).
 *
 * @return array{ok:bool, reason?:string}
 */
function menu_user_access_save(int $userId, ?int $roleId, array $overrides, ?int $managerUserId, int $actorId): array {
    if (!customer_hierarchy_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    if ($roleId !== null && !isset(menu_roles_for($managerUserId)[$roleId])) {
        return ['ok' => false, 'reason' => 'role_not_found'];
    }
    $grantable = $managerUserId !== null ? menu_access_effective($managerUserId) : null;
    $clean = [];
    foreach ($overrides as $key => $mode) {
        $key = (string)$key;
        if (!isset(menu_catalog()[$key]) || !in_array($mode, ['allow', 'deny'], true)) continue;
        if ($mode === 'allow' && $grantable !== null && !in_array($key, $grantable, true)) continue;
        $clean[$key] = $mode;
    }
    db_transaction(function (PDO $db) use ($userId, $roleId, $clean, $actorId): void {
        if ($roleId === null && $clean === []) {
            $db->prepare('DELETE FROM ellsms_user_menu_access WHERE user_id = ?')->execute([$userId]);
        } else {
            $db->prepare('INSERT INTO ellsms_user_menu_access (user_id, role_id, updated_by_user_id) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), updated_by_user_id = VALUES(updated_by_user_id)')
               ->execute([$userId, $roleId, $actorId]);
        }
        $db->prepare('DELETE FROM ellsms_user_menu_overrides WHERE user_id = ?')->execute([$userId]);
        $ins = $db->prepare('INSERT INTO ellsms_user_menu_overrides (user_id, menu_key, mode) VALUES (?,?,?)');
        foreach ($clean as $key => $mode) $ins->execute([$userId, $key, $mode]);
        audit($actorId, 'menu_access.saved', "user=#{$userId} role=" . ($roleId ?? '-') . ' overrides=' . json_encode($clean));
    });
    menu_access_reset_cache();
    return ['ok' => true];
}

function menu_access_error_message(string $reason): string {
    return [
        'name_required'  => 'نام نقش را وارد کنید.',
        'role_not_found' => 'نقش انتخاب‌شده یافت نشد یا متعلق به شما نیست.',
        'not_migrated'   => 'جدول‌های دسترسی منو هنوز در پایگاه داده ساخته نشده‌اند (مهاجرت 2026_10_12_customer_hierarchy.sql).',
    ][$reason] ?? 'عملیات انجام نشد.';
}
