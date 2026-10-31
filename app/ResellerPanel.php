<?php
/**
 * ELLSMS — the reseller panel (پنل نمایندگی), modeled on the Negar platform's reseller features.
 *
 * A reseller (ellsms_customer_accounts.customer_type = 'reseller') gets its own panel under
 * /reseller: dashboard, customers (app/CustomerHierarchy.php), named tariffs
 * (app/ResellerTariffs.php), packages and vouchers (app/ResellerPackages.php), finance, its
 * customers' tickets, announcements, sign-up requests, staff and its white-label brand.
 *
 * Who acts in the panel (reseller_context()):
 *   - the reseller itself, with every permission;
 *   - one of its staff accounts (ellsms_reseller_staff, Negar's sub-users with roles), with the
 *     permissions the reseller gave it. A staff account works only in its reseller's panel
 *     (reseller_staff_enforce(), called from require_login()); everything it does is done for the
 *     reseller (the money is the reseller's) and audited under the staff account's own id.
 *
 * White-label (reseller_brand_for_host()): a reseller may serve its panel from its own host name
 * with its own name, logo, colour and support details. On that host the login page and the panel
 * show the reseller's brand, sign-ups go to that reseller, and (by default) only the reseller, its
 * staff and its own customers may sign in.
 *
 * Schema: db/migrations/2026_10_24_reseller_panel.sql. Until it is applied the panel answers that it
 * is not installed and nothing else changes.
 */

declare(strict_types=1);

/** Permission key => label. The reseller itself always has all of them. */
const RESELLER_STAFF_PERMISSIONS = [
    'customers.view'       => 'مشاهده‌ی مشتریان و گزارش‌ها',
    'customers.manage'     => 'ساخت و ویرایش مشتریان',
    'credit.manage'        => 'انتقال اعتبار به مشتریان',
    'tariffs.manage'       => 'تعرفه‌ها',
    'packages.manage'      => 'بسته‌ها و کدهای تخفیف',
    'tickets.manage'       => 'پاسخ به تیکت‌ها',
    'announcements.manage' => 'اطلاعیه‌ها',
    'registrations.manage' => 'درخواست‌های ثبت‌نام',
    'finance.view'         => 'گزارش مالی',
    'payments.manage'      => 'بررسی پرداخت‌های مشتریان، کارت‌ها و حساب‌ها',
];

/** Pages a staff account may open outside its reseller panel. */
const RESELLER_STAFF_ALLOWED_PAGES = [
    'logout.php', 'profile.php', 'notifications.php', 'verify-2fa.php', 'brand-logo.php', 'index.php',
    // A customer's receipt or cheque image, opened from /reseller/payments (it checks payments.manage itself).
    'payment-receipt.php',
];

const RESELLER_BRAND_LOGO_MAX_BYTES = 524288;

/* ==========================================================================
   Who is acting
   ========================================================================== */

/** The staff row of $userId (active or not), or null. Cached per process. */
function reseller_staff_row(int $userId): ?array {
    static $cache = [];
    if ($userId <= 0 || !reseller_panel_available()) return null;
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    if (count($cache) > 200) $cache = [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_staff WHERE staff_user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $cache[$userId] = $row ? [
        'staff_user_id'    => (int)$row['staff_user_id'],
        'reseller_user_id' => (int)$row['reseller_user_id'],
        'permissions'      => reseller_permissions_parse((string)$row['permissions']),
        'active'           => (int)$row['active'] === 1,
        'created_at'       => (string)$row['created_at'],
    ] : null;
}

/** @return list<string> the known permission keys in $csv */
function reseller_permissions_parse(string $csv): array {
    $keys = array_filter(array_map('trim', explode(',', $csv)));
    return array_values(array_intersect(array_keys(RESELLER_STAFF_PERMISSIONS), $keys));
}

function reseller_is_reseller(int $userId): bool {
    return $userId > 0 && customer_hierarchy_available() && customer_account($userId)['customer_type'] === 'reseller';
}

/**
 * The reseller panel context of the logged-in $user, or null when it has none:
 * {reseller_id, actor_id, staff, permissions (null = all), account}.
 */
function reseller_context(array $user): ?array {
    if (($user['role'] ?? null) === 'admin' || !reseller_panel_available()) return null;
    $userId = (int)($user['id'] ?? 0);
    if (reseller_is_reseller($userId)) {
        return ['reseller_id' => $userId, 'actor_id' => $userId, 'staff' => false, 'permissions' => null, 'account' => customer_account($userId)];
    }
    $staff = reseller_staff_row($userId);
    if ($staff !== null && $staff['active'] && reseller_is_reseller($staff['reseller_user_id'])) {
        return ['reseller_id' => $staff['reseller_user_id'], 'actor_id' => $userId, 'staff' => true, 'permissions' => $staff['permissions'], 'account' => customer_account($staff['reseller_user_id'])];
    }
    return null;
}

/** Whether the context may do $permission. 'owner' = the reseller itself only (settings, staff). */
function reseller_can(array $ctx, string $permission): bool {
    if ($permission === 'owner') return !$ctx['staff'];
    return $ctx['permissions'] === null || in_array($permission, $ctx['permissions'], true);
}

/**
 * For every reseller page: the logged-in user and its context, or a 403. $permission is checked when
 * given.
 *
 * @return array{0:array, 1:array}
 */
function require_reseller(?string $permission = null): array {
    $me = require_login();
    if (!reseller_panel_available()) {
        http_response_code(503);
        exit('پنل نمایندگی هنوز نصب نشده است (مهاجرت 2026_10_24_reseller_panel.sql).');
    }
    $ctx = reseller_context($me);
    if ($ctx === null) {
        http_response_code(403);
        exit('۴۰۳ — این بخش فقط برای نمایندگان و کارمندان آن‌هاست.');
    }
    // A staff account follows its reseller's own menu access: a reseller whose panel menu the admin
    // closed has no panel for its staff either (the reseller itself is checked by require_login()).
    if ($ctx['staff'] && !menu_access_allows(['id' => $ctx['reseller_id'], 'role' => 'user'], 'subaccounts')) {
        http_response_code(403);
        exit('۴۰۳ — دسترسی به پنل نمایندگی برای این حساب فعال نیست.');
    }
    if ($permission !== null && !reseller_can($ctx, $permission)) {
        http_response_code(403);
        exit('۴۰۳ — این بخش برای حساب کارمندی شما فعال نشده است.');
    }
    return [$me, $ctx];
}

/** A POST in the panel: CSRF, the permission, and never inside a support session. Redirects on refusal. */
function reseller_post_guard(array $ctx, string $permission, string $back): void {
    csrf_check();
    if (impersonation_guard_post('reseller.manage')) {
        redirect($back);
    }
    if (!reseller_can($ctx, $permission)) {
        flash('error', 'این کار برای حساب شما مجاز نیست.');
        redirect($back);
    }
}

/**
 * A staff account works only in its reseller's panel: any other page sends it there. Called from
 * require_login() for every authenticated page.
 */
function reseller_staff_enforce(array $user, ?string $script = null): void {
    if (($user['role'] ?? null) === 'admin' || !reseller_panel_available()) return;
    $staff = reseller_staff_row((int)$user['id']);
    if ($staff === null) return;
    $script = basename($script ?? (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!$staff['active']) {
        http_response_code(403);
        exit('۴۰۳ — حساب کارمندی شما غیرفعال شده است.');
    }
    if (str_starts_with($script, 'reseller') || in_array($script, RESELLER_STAFF_ALLOWED_PAGES, true)) {
        if ($script === 'index.php') redirect('/reseller');
        return;
    }
    redirect('/reseller');
}

/** The reseller's own user row (for its domain, organization and name), from the identity adapter. */
function reseller_owner_user(int $resellerId): array {
    $row = backend_find_user_by_id($resellerId) ?? ['id' => $resellerId, 'username' => '#' . $resellerId, 'first_name' => '', 'last_name' => ''];
    $row['id'] = $resellerId;
    $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $row['organization_id'] = user_default_organization_id($resellerId);
    return $row;
}

/* ==========================================================================
   The sub-tree
   ========================================================================== */

/** @return list<int> every account below $resellerId, nearest first, bounded by CUSTOMER_MAX_DEPTH */
function reseller_descendant_ids(int $resellerId): array {
    if (!customer_hierarchy_available()) return [];
    $out = [];
    $frontier = [$resellerId];
    for ($depth = 0; $depth < CUSTOMER_MAX_DEPTH && $frontier !== []; $depth++) {
        $st = db()->prepare('SELECT user_id FROM ellsms_customer_accounts WHERE parent_user_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')');
        $st->execute($frontier);
        $next = [];
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
            if ($id === $resellerId || isset($out[$id])) continue;
            $out[$id] = true;
            $next[] = $id;
        }
        $frontier = $next;
    }
    return array_keys($out);
}

/** Whether $userId is $resellerId itself, one of its staff, or anywhere below it. */
function reseller_tree_contains(int $resellerId, int $userId): bool {
    if ($userId === $resellerId) return true;
    $staff = reseller_staff_row($userId);
    if ($staff !== null && $staff['reseller_user_id'] === $resellerId) return true;
    return customer_is_ancestor($resellerId, $userId);
}

/** The nearest reseller above $userId, or null. */
function reseller_nearest_ancestor(int $userId): ?int {
    foreach (customer_ancestor_ids($userId) as $ancestorId) {
        if (customer_account($ancestorId)['customer_type'] === 'reseller') return $ancestorId;
    }
    return null;
}

/* ==========================================================================
   Customers
   ========================================================================== */

/**
 * The reseller's direct customers with Negar's filters (q = username / name / email / mobile,
 * type, status, has_subset, tariff, credit range, created range, order). Paged.
 *
 * @return array{rows:list<array>, total:int}
 */
function reseller_customers_search(int $resellerId, array $filters, int $page = 1, int $perPage = 25): array {
    if (!customer_hierarchy_available()) return ['rows' => [], 'total' => 0];
    $where = ['c.parent_user_id = ?'];
    $params = [$resellerId];
    $type = (string)($filters['type'] ?? '');
    if (isset(CUSTOMER_TYPES[$type])) {
        $where[] = 'c.customer_type = ?';
        $params[] = $type;
    }
    $status = (string)($filters['status'] ?? '');
    $today = customer_today();
    if ($status === 'active') {
        $where[] = 'c.suspended = 0 AND (c.expires_on IS NULL OR c.expires_on >= ?)';
        $params[] = $today;
    } elseif ($status === 'suspended') {
        $where[] = 'c.suspended = 1';
    } elseif ($status === 'expired') {
        $where[] = 'c.expires_on < ?';
        $params[] = $today;
    } elseif ($status === 'expiring') {
        $where[] = 'c.expires_on BETWEEN ? AND ?';
        $params[] = $today;
        $params[] = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
    }
    if (($filters['has_subset'] ?? '') === '1') {
        $where[] = 'c.max_subset_limit > 0';
    }
    $tariff = (int)($filters['tariff_id'] ?? 0);
    if ($tariff > 0) {
        $where[] = 'c.tariff_id = ?';
        $params[] = $tariff;
    }
    foreach (['min_credit' => '>=', 'max_credit' => '<='] as $key => $op) {
        $raw = trim(from_persian_digits((string)($filters[$key] ?? '')));
        if ($raw !== '' && ctype_digit(str_replace(',', '', $raw))) {
            $where[] = "COALESCE(w.available_balance, 0) {$op} ?";
            $params[] = (int)str_replace(',', '', $raw);
        }
    }
    foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
        $date = $filters[$key] ?? null;
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$fromUtc, $toUtc] = local_day_range_to_utc($date, $date);
            $where[] = "c.created_at {$op} ?";
            $params[] = $key === 'from' ? $fromUtc : $toUtc;
        }
    }
    $sql = 'SELECT c.*, COALESCE(w.available_balance, 0) balance FROM ellsms_customer_accounts c
            LEFT JOIN ellsms_wallet_accounts w ON w.user_id = c.user_id WHERE ' . implode(' AND ', $where);
    $order = match ((string)($filters['order'] ?? '')) {
        'expiry'  => 'c.expires_on IS NULL, c.expires_on ASC',
        'credit'  => 'balance DESC',
        'oldest'  => 'c.created_at ASC',
        default   => 'c.created_at DESC, c.user_id DESC',
    };
    $st = db()->prepare($sql . ' ORDER BY ' . $order);
    $st->execute($params);
    $rows = $st->fetchAll();

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '' && $rows) {
        $match = array_flip(backend_user_ids_matching(array_column($rows, 'user_id'), from_persian_digits($q)));
        $rows = array_values(array_filter($rows, static fn(array $r): bool => isset($match[(int)$r['user_id']])));
    }
    $total = count($rows);
    $rows = array_slice($rows, max(0, ($page - 1) * $perPage), $perPage);
    $identities = backend_user_details_by_ids(array_column($rows, 'user_id'));
    $out = [];
    foreach ($rows as $row) {
        $account = customer_account_normalize($row);
        $id = $account['user_id'];
        $out[] = [
            'account'  => $account,
            'identity' => $identities[$id] ?? ['username' => '#' . $id, 'first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => '', 'active' => 1, 'deleted' => 0],
            'balance'  => (int)$row['balance'],
            'block'    => customer_account_block_reason($id),
            'created'  => (string)$row['created_at'],
            'children' => (int)$account['max_subset_limit'] > 0 ? count(customer_child_ids($id)) : 0,
        ];
    }
    return ['rows' => $out, 'total' => $total];
}

/* ==========================================================================
   Dashboard
   ========================================================================== */

/** The numbers on the reseller dashboard. */
function reseller_dashboard_stats(int $resellerId): array {
    require_once __DIR__ . '/Dashboard.php';
    $account = customer_account($resellerId);
    $children = customer_child_ids($resellerId);
    $tree = reseller_descendant_ids($resellerId);
    $accounts = customer_accounts_by_ids($children);
    $today = customer_today();
    $counts = ['active' => 0, 'suspended' => 0, 'expired' => 0, 'expiring' => 0, 'resellers' => 0, 'organs' => 0];
    $soon = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
    foreach ($accounts as $acc) {
        if ($acc['suspended']) $counts['suspended']++;
        elseif ($acc['expires_on'] !== null && $acc['expires_on'] < $today) $counts['expired']++;
        else $counts['active']++;
        if ($acc['expires_on'] !== null && $acc['expires_on'] >= $today && $acc['expires_on'] <= $soon) $counts['expiring']++;
        if ($acc['customer_type'] === 'reseller') $counts['resellers']++;
        if ($acc['customer_type'] === 'organ') $counts['organs']++;
    }
    $childBalance = 0;
    if ($children) {
        $st = db()->prepare('SELECT COALESCE(SUM(available_balance), 0) FROM ellsms_wallet_accounts WHERE user_id IN (' . implode(',', array_fill(0, count($children), '?')) . ')');
        $st->execute($children);
        $childBalance = (int)$st->fetchColumn();
    }
    $profit = db()->prepare('SELECT COALESCE(SUM(profit), 0) FROM ellsms_reseller_profits WHERE reseller_user_id = ? AND profit_day >= ?');
    $profit->execute([$resellerId, (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')]);
    $profitDays = db()->prepare('SELECT profit_day, SUM(spent) spent, SUM(profit) profit FROM ellsms_reseller_profits WHERE reseller_user_id = ? GROUP BY profit_day ORDER BY profit_day DESC LIMIT 14');
    $profitDays->execute([$resellerId]);

    return [
        'account'        => $account,
        'balance'        => wallet_balance($resellerId),
        'direct'         => count($children),
        'tree'           => count($tree),
        'counts'         => $counts,
        'quota_used'     => customer_used_subset($resellerId),
        'child_balance'  => $childBalance,
        'profit_30'      => (int)$profit->fetchColumn(),
        'profit_days'    => $profitDays->fetchAll(),
        'today'          => $tree ? dashboard_today_counts($tree) : ['sent' => 0, 'delivered' => 0, 'failed' => 0],
        'daily'          => $tree ? dashboard_daily_sent($tree, 14) : [],
        'open_tickets'   => reseller_ticket_count($resellerId, 'open'),
        'registrations'  => reseller_registration_count($resellerId),
        'package_sales'  => reseller_package_sales_total($resellerId, 30),
    ];
}

/* ==========================================================================
   Settings: brand, host, sign-up, support
   ========================================================================== */

function reseller_settings_defaults(int $resellerId): array {
    return [
        'reseller_user_id'     => $resellerId,
        'brand_enabled'        => false,
        'host'                 => null,
        'brand_name'           => '',
        'brand_tagline'        => '',
        'logo_file'            => null,
        'primary_color'        => '',
        'support_phone'        => '',
        'support_email'        => '',
        'login_note'           => '',
        'host_only_own_tree'   => true,
        'signup_enabled'       => false,
        'signup_code'          => null,
        'signup_customer_type' => 'normal',
        'signup_tariff_bp'     => null,
        'signup_tariff_id'     => null,
        'signup_expiry_days'   => null,
        'signup_package_id'    => null,
        'tickets_to_reseller'  => true,
        'exists'               => false,
    ];
}

function reseller_settings_normalize(array $row): array {
    $int = static fn($v): ?int => $v !== null && $v !== '' ? (int)$v : null;
    return [
        'reseller_user_id'     => (int)$row['reseller_user_id'],
        'brand_enabled'        => (int)$row['brand_enabled'] === 1,
        'host'                 => $row['host'] !== null && $row['host'] !== '' ? (string)$row['host'] : null,
        'brand_name'           => (string)$row['brand_name'],
        'brand_tagline'        => (string)$row['brand_tagline'],
        'logo_file'            => $row['logo_file'] !== null && $row['logo_file'] !== '' ? (string)$row['logo_file'] : null,
        'primary_color'        => (string)$row['primary_color'],
        'support_phone'        => (string)$row['support_phone'],
        'support_email'        => (string)$row['support_email'],
        'login_note'           => (string)$row['login_note'],
        'host_only_own_tree'   => (int)$row['host_only_own_tree'] === 1,
        'signup_enabled'       => (int)$row['signup_enabled'] === 1,
        'signup_code'          => $row['signup_code'] !== null && $row['signup_code'] !== '' ? (string)$row['signup_code'] : null,
        'signup_customer_type' => (string)$row['signup_customer_type'],
        'signup_tariff_bp'     => $int($row['signup_tariff_bp']),
        'signup_tariff_id'     => $int($row['signup_tariff_id']),
        'signup_expiry_days'   => $int($row['signup_expiry_days']),
        'signup_package_id'    => $int($row['signup_package_id']),
        'tickets_to_reseller'  => (int)$row['tickets_to_reseller'] === 1,
        'exists'               => true,
    ];
}

function reseller_settings(int $resellerId): array {
    if ($resellerId <= 0 || !reseller_panel_available()) return reseller_settings_defaults($resellerId);
    $st = db()->prepare('SELECT * FROM ellsms_reseller_settings WHERE reseller_user_id = ?');
    $st->execute([$resellerId]);
    $row = $st->fetch();
    return $row ? reseller_settings_normalize($row) : reseller_settings_defaults($resellerId);
}

/** 'sms.example.com' from what a reseller typed ('https://SMS.Example.com/login'), or null when it is not a host name. */
function reseller_normalize_host(string $raw): ?string {
    $raw = strtolower(trim($raw));
    if ($raw === '') return null;
    $raw = preg_replace('#^[a-z]+://#', '', $raw);
    $raw = explode('/', (string)$raw, 2)[0];
    $raw = explode(':', $raw, 2)[0];
    $raw = rtrim($raw, '.');
    if (strlen($raw) > 190 || !preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $raw)) return null;
    return $raw;
}

/** The platform's own host names, which no reseller may claim. */
function reseller_reserved_hosts(): array {
    $hosts = [];
    $appHost = parse_url(app_url(), PHP_URL_HOST);
    if (is_string($appHost) && $appHost !== '') $hosts[] = strtolower($appHost);
    foreach (array_filter(array_map('trim', explode(',', (string)env('RESELLER_RESERVED_HOSTS', '')))) as $h) {
        $hosts[] = strtolower($h);
    }
    return array_values(array_unique($hosts));
}

/**
 * Saves the reseller's settings. Only the keys in $input are changed.
 *
 * @return array{ok:bool, reason?:string}
 */
function reseller_settings_save(int $resellerId, array $input, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $current = reseller_settings($resellerId);
    $next = $current;

    foreach (['brand_enabled', 'host_only_own_tree', 'signup_enabled', 'tickets_to_reseller'] as $flag) {
        if (array_key_exists($flag, $input)) $next[$flag] = (bool)$input[$flag];
    }
    foreach (['brand_name' => 120, 'brand_tagline' => 190, 'support_phone' => 40, 'support_email' => 190, 'login_note' => 500] as $key => $max) {
        if (array_key_exists($key, $input)) $next[$key] = mb_substr(trim((string)$input[$key]), 0, $max);
    }
    if (array_key_exists('host', $input)) {
        $raw = trim((string)$input['host']);
        $host = reseller_normalize_host($raw);
        if ($raw !== '' && $host === null) return ['ok' => false, 'reason' => 'invalid_host'];
        if ($host !== null && in_array($host, reseller_reserved_hosts(), true)) return ['ok' => false, 'reason' => 'host_reserved'];
        $next['host'] = $host;
    }
    if (array_key_exists('primary_color', $input)) {
        $color = strtolower(trim((string)$input['primary_color']));
        if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/', $color)) return ['ok' => false, 'reason' => 'invalid_color'];
        $next['primary_color'] = $color;
    }
    if ($next['support_email'] !== '' && filter_var($next['support_email'], FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'reason' => 'invalid_email'];
    }
    if (array_key_exists('signup_customer_type', $input)) {
        $type = (string)$input['signup_customer_type'];
        if (!in_array($type, ['normal', 'organ'], true)) return ['ok' => false, 'reason' => 'invalid_type'];
        $next['signup_customer_type'] = $type;
    }
    if (array_key_exists('signup_tariff_bp', $input)) {
        $bp = $input['signup_tariff_bp'];
        if ($bp !== null && ($bp < customer_effective_tariff_bp($resellerId) || $bp > CUSTOMER_TARIFF_MAX_BP)) return ['ok' => false, 'reason' => 'tariff_below_parent'];
        $next['signup_tariff_bp'] = $bp;
    }
    if (array_key_exists('signup_tariff_id', $input)) {
        $tid = $input['signup_tariff_id'];
        if ($tid !== null && reseller_tariff_owned($resellerId, (int)$tid) === null) return ['ok' => false, 'reason' => 'tariff_not_found'];
        $next['signup_tariff_id'] = $tid;
    }
    if (array_key_exists('signup_package_id', $input)) {
        $pid = $input['signup_package_id'];
        if ($pid !== null && reseller_package_owned($resellerId, (int)$pid) === null) return ['ok' => false, 'reason' => 'package_not_found'];
        $next['signup_package_id'] = $pid;
    }
    if (array_key_exists('signup_expiry_days', $input)) {
        $days = $input['signup_expiry_days'];
        $next['signup_expiry_days'] = $days !== null ? max(1, min(3650, (int)$days)) : null;
    }
    if ($next['signup_enabled'] && $next['signup_code'] === null) {
        $next['signup_code'] = reseller_new_signup_code();
    }
    if (!empty($input['regenerate_signup_code'])) {
        $next['signup_code'] = reseller_new_signup_code();
    }
    if ($next['brand_enabled'] && $next['brand_name'] === '') return ['ok' => false, 'reason' => 'brand_name_required'];

    try {
        // A plain INSERT or UPDATE, never ON DUPLICATE KEY UPDATE: that would also fire on another
        // reseller's host or sign-up code (both unique) and overwrite that reseller's row.
        $columns = ['brand_enabled', 'host', 'brand_name', 'brand_tagline', 'logo_file', 'primary_color', 'support_phone', 'support_email',
                    'login_note', 'host_only_own_tree', 'signup_enabled', 'signup_code', 'signup_customer_type', 'signup_tariff_bp',
                    'signup_tariff_id', 'signup_expiry_days', 'signup_package_id', 'tickets_to_reseller'];
        $values = [];
        foreach ($columns as $column) {
            $values[] = is_bool($next[$column]) ? ($next[$column] ? 1 : 0) : $next[$column];
        }
        if ($current['exists']) {
            db()->prepare('UPDATE ellsms_reseller_settings SET ' . implode(' = ?, ', $columns) . ' = ?, updated_by_user_id = ? WHERE reseller_user_id = ?')
                ->execute([...$values, $actorId, $resellerId]);
        } else {
            db()->prepare('INSERT INTO ellsms_reseller_settings (reseller_user_id, ' . implode(', ', $columns) . ', updated_by_user_id)
                           VALUES (' . implode(',', array_fill(0, count($columns) + 2, '?')) . ')')
                ->execute([$resellerId, ...$values, $actorId]);
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'reason' => str_contains($e->getMessage(), 'host') ? 'host_taken' : 'signup_code_taken'];
        }
        throw $e;
    }
    reseller_brand_cache_reset();
    audit($actorId, 'reseller.settings_saved', "reseller=#{$resellerId} host=" . ($next['host'] ?? '-') . ' brand=' . ($next['brand_enabled'] ? '1' : '0') . ' signup=' . ($next['signup_enabled'] ? '1' : '0'));
    return ['ok' => true];
}

function reseller_new_signup_code(): string {
    return strtolower(bin2hex(random_bytes(5)));
}

/** The reseller whose sign-up link is $code, when the link is switched on. */
function reseller_by_signup_code(string $code): ?int {
    $code = strtolower(trim($code));
    if (!preg_match('/^[a-z0-9]{6,32}$/', $code) || !reseller_panel_available()) return null;
    $st = db()->prepare('SELECT reseller_user_id FROM ellsms_reseller_settings WHERE signup_code = ? AND signup_enabled = 1');
    $st->execute([$code]);
    $id = $st->fetchColumn();
    return $id !== false && reseller_is_reseller((int)$id) ? (int)$id : null;
}

/** The public sign-up URL of a reseller: on its own host when it has one, else the platform's. */
function reseller_signup_url(array $settings): ?string {
    if ($settings['signup_code'] === null) return null;
    $base = $settings['brand_enabled'] && $settings['host'] !== null ? 'https://' . $settings['host'] : rtrim(app_url(), '/');
    return $base . '/register?r=' . $settings['signup_code'];
}

/* ==========================================================================
   White-label brand by host
   ========================================================================== */

/** The request's host name, lower case and without a port. */
function request_host(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return explode(':', $host, 2)[0];
}

function &reseller_brand_cache(): array {
    static $cache = [];
    return $cache;
}

function reseller_brand_cache_reset(): void {
    $cache = &reseller_brand_cache();
    $cache = [];
}

/** The settings of the reseller whose branded host is $host, or null. Cached per process. */
function reseller_brand_for_host(?string $host = null): ?array {
    $host ??= request_host();
    if ($host === '') return null;
    $cache = &reseller_brand_cache();
    if (array_key_exists($host, $cache)) return $cache[$host];
    $brand = null;
    if (reseller_panel_available()) {
        try {
            $st = db()->prepare('SELECT * FROM ellsms_reseller_settings WHERE host = ? AND brand_enabled = 1');
            $st->execute([$host]);
            $row = $st->fetch();
            if ($row && reseller_is_reseller((int)$row['reseller_user_id'])) {
                $brand = reseller_settings_normalize($row);
            }
        } catch (PDOException $e) {
            Logger::warning('reseller.brand_lookup_failed', ['exception' => $e]);
        }
    }
    return $cache[$host] = $brand;
}

/**
 * What the current request should be branded as: the platform's name and logo, or the reseller's on
 * its own host.
 *
 * @return array{name:string, tagline:string, logo:string, color:string, support_phone:string, support_email:string, login_note:string, reseller_id:?int}
 */
function current_brand(): array {
    $brand = reseller_brand_for_host();
    if ($brand === null) {
        return [
            'name' => 'داتیس رایان گستر ایرانیان', 'tagline' => 'سامانه مدیریت و ارسال پیامک',
            'logo' => asset_url('/assets/img/brand/logo-animated.svg'), 'color' => '',
            'support_phone' => '', 'support_email' => '', 'login_note' => '', 'reseller_id' => null,
        ];
    }
    return [
        'name'          => $brand['brand_name'],
        'tagline'       => $brand['brand_tagline'] !== '' ? $brand['brand_tagline'] : 'سامانه مدیریت و ارسال پیامک',
        'logo'          => $brand['logo_file'] !== null ? '/brand/logo?r=' . $brand['reseller_user_id'] . '&v=' . substr(md5($brand['logo_file']), 0, 8) : '',
        'color'         => $brand['primary_color'],
        'support_phone' => $brand['support_phone'],
        'support_email' => $brand['support_email'],
        'login_note'    => $brand['login_note'],
        'reseller_id'   => $brand['reseller_user_id'],
    ];
}

/** A <style> that recolors the panel's accent with the brand's colour, or '' when it has none. */
function brand_color_style(array $brand): string {
    $color = (string)($brand['color'] ?? '');
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) return '';
    return '<style>:root{--indigo:' . $color . ';--blue:' . $color . ';--lp-accent:' . $color . '}</style>';
}

/** Why $userId may not sign in on this (branded) host, or null when it may. */
function reseller_host_login_refusal(int $userId, ?array $brand = null): ?string {
    $brand ??= reseller_brand_for_host();
    if ($brand === null || !$brand['host_only_own_tree']) return null;
    return reseller_tree_contains($brand['reseller_user_id'], $userId) ? null : 'not_on_this_host';
}

function reseller_brand_logo_dir(): string {
    return dirname(__DIR__) . '/storage/brand-logos';
}

/**
 * Stores an uploaded logo ($_FILES entry) for the reseller: PNG, JPEG or WebP, at most 512 KB, and a
 * real image. Replaces the previous one.
 *
 * @return array{ok:bool, reason?:string}
 */
function reseller_brand_logo_store(int $resellerId, array $file, int $actorId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'reason' => 'upload_failed'];
    }
    return reseller_brand_logo_store_path($resellerId, (string)$file['tmp_name'], (int)($file['size'] ?? 0), $actorId);
}

/** reseller_brand_logo_store() for a file already on disk (also what the tests call). */
function reseller_brand_logo_store_path(int $resellerId, string $path, int $size, int $actorId): array {
    if ($size <= 0 || $size > RESELLER_BRAND_LOGO_MAX_BYTES) return ['ok' => false, 'reason' => 'logo_too_large'];
    $info = @getimagesize($path);
    $ext = match ($info[2] ?? null) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) return ['ok' => false, 'reason' => 'logo_type'];
    $dir = reseller_brand_logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return ['ok' => false, 'reason' => 'storage'];
    $name = $resellerId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@copy($path, $dir . '/' . $name)) return ['ok' => false, 'reason' => 'storage'];
    $old = reseller_settings($resellerId)['logo_file'];
    if (!reseller_settings($resellerId)['exists']) reseller_settings_save($resellerId, [], $actorId);
    db()->prepare('UPDATE ellsms_reseller_settings SET logo_file = ?, updated_by_user_id = ? WHERE reseller_user_id = ?')->execute([$name, $actorId, $resellerId]);
    if ($old !== null && preg_match('/^\d+-[a-f0-9]{12}\.(png|jpg|webp)$/', $old)) @unlink($dir . '/' . $old);
    reseller_brand_cache_reset();
    audit($actorId, 'reseller.logo_saved', "reseller=#{$resellerId}");
    return ['ok' => true];
}

/* ==========================================================================
   Staff
   ========================================================================== */

/** @return list<array> the reseller's staff with their identities */
function reseller_staff_list(int $resellerId): array {
    if (!reseller_panel_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_staff WHERE reseller_user_id = ? ORDER BY created_at DESC');
    $st->execute([$resellerId]);
    $rows = $st->fetchAll();
    $identities = backend_user_details_by_ids(array_column($rows, 'staff_user_id'));
    return array_map(static fn(array $r): array => [
        'staff_user_id' => (int)$r['staff_user_id'],
        'permissions'   => reseller_permissions_parse((string)$r['permissions']),
        'active'        => (int)$r['active'] === 1,
        'created_at'    => (string)$r['created_at'],
        'identity'      => $identities[(int)$r['staff_user_id']] ?? ['username' => '#' . $r['staff_user_id'], 'first_name' => '', 'last_name' => '', 'mobile' => '', 'email' => ''],
    ], $rows);
}

/**
 * Creates a staff account for the reseller: a backend account in the reseller's domain (POST
 * /api/users/), panel access, and its staff row. It has no wallet use, no hierarchy row and no
 * organization of its own.
 *
 * @return array{ok:bool, reason?:string, message?:string, user_id?:int}
 */
function reseller_staff_create(int $resellerId, array $input, array $permissions, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $username  = trim((string)($input['username'] ?? ''));
    $password  = (string)($input['password'] ?? '');
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName  = trim((string)($input['last_name'] ?? ''));
    $email     = trim((string)($input['email'] ?? ''));
    $mobile    = normalize_msisdn((string)($input['mobile'] ?? ''));
    $nationalId = trim(from_persian_digits((string)($input['national_id'] ?? '')));
    if ($username === '' || strlen($password) < 8 || $firstName === '' || $lastName === '' || $email === '' || !$mobile || strlen($nationalId) !== 10) {
        return ['ok' => false, 'reason' => 'staff_fields'];
    }
    $domainId = backend_user_domain_id($resellerId) ?? (int)(backend_list_domains()[0]['id'] ?? 0);
    if ($domainId <= 0) return ['ok' => false, 'reason' => 'no_domain'];
    [$ok, $info, $created] = backend_create_account([
        'username' => $username, 'password' => $password, 'first_name' => $firstName, 'last_name' => $lastName,
        'email' => $email, 'mobile' => (int)$mobile, 'national_id' => $nationalId, 'domain_id' => $domainId,
        'gender' => ($input['gender'] ?? 'MALE') === 'FEMALE' ? 'FEMALE' : 'MALE', 'code' => (string)random_int(10000000, 99999999),
        'daily_limit' => 0, 'min_credit_notify' => 0, 'limit_time_from' => '00:00', 'limit_time_to' => '23:59',
    ]);
    if (!$ok || !$created || empty($created['id'])) {
        return ['ok' => false, 'reason' => 'backend_failed', 'message' => (string)$info];
    }
    $staffId = (int)$created['id'];
    reseller_staff_attach($resellerId, $staffId, $permissions, $actorId);
    return ['ok' => true, 'user_id' => $staffId];
}

/** Records $staffId as the reseller's staff account and grants it panel access. */
function reseller_staff_attach(int $resellerId, int $staffId, array $permissions, int $actorId): void {
    db_transaction(function (PDO $db) use ($resellerId, $staffId, $permissions, $actorId): void {
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?) ON DUPLICATE KEY UPDATE panel_access = 1')
           ->execute([$staffId, '']);
        $db->prepare('INSERT INTO ellsms_reseller_staff (staff_user_id, reseller_user_id, permissions, created_by_user_id) VALUES (?,?,?,?)')
           ->execute([$staffId, $resellerId, implode(',', reseller_permissions_parse(implode(',', $permissions))), $actorId]);
    });
    audit($actorId, 'reseller.staff_created', "reseller=#{$resellerId} staff=#{$staffId}");
}

/** Changes one of the reseller's staff accounts: permissions and/or active. */
function reseller_staff_update(int $resellerId, int $staffId, ?array $permissions, ?bool $active, int $actorId): array {
    $row = reseller_staff_row($staffId);
    if ($row === null || $row['reseller_user_id'] !== $resellerId) return ['ok' => false, 'reason' => 'staff_not_found'];
    $sets = [];
    $params = [];
    if ($permissions !== null) {
        $sets[] = 'permissions = ?';
        $params[] = implode(',', reseller_permissions_parse(implode(',', $permissions)));
    }
    if ($active !== null) {
        $sets[] = 'active = ?';
        $params[] = $active ? 1 : 0;
    }
    if ($sets) {
        $params[] = $staffId;
        db()->prepare('UPDATE ellsms_reseller_staff SET ' . implode(', ', $sets) . ' WHERE staff_user_id = ?')->execute($params);
        audit($actorId, 'reseller.staff_updated', "reseller=#{$resellerId} staff=#{$staffId}");
    }
    return ['ok' => true];
}

/* ==========================================================================
   Announcements
   ========================================================================== */

/** @return list<array> the reseller's announcements, newest first */
function reseller_announcements_for_owner(int $resellerId): array {
    if (!reseller_panel_available()) return [];
    $st = db()->prepare('SELECT * FROM ellsms_reseller_announcements WHERE owner_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 200');
    $st->execute([$resellerId]);
    return $st->fetchAll();
}

/**
 * Publishes (or edits) an announcement. A new active one is also delivered as a panel notification
 * to every account in its audience: the direct customers, or the whole sub-tree.
 *
 * @return array{ok:bool, reason?:string, id?:int, notified?:int}
 */
function reseller_announcement_save(int $resellerId, ?int $id, array $input, int $actorId): array {
    if (!reseller_panel_available()) return ['ok' => false, 'reason' => 'not_migrated'];
    $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 160);
    $body = mb_substr(trim((string)($input['body'] ?? '')), 0, 5000);
    if ($title === '' || $body === '') return ['ok' => false, 'reason' => 'announcement_fields'];
    $severity = in_array($input['severity'] ?? '', ['info', 'success', 'warning', 'error'], true) ? (string)$input['severity'] : 'info';
    $audience = ($input['audience'] ?? 'tree') === 'direct' ? 'direct' : 'tree';
    $active = !empty($input['active']) ? 1 : 0;
    $startsOn = $input['starts_on'] ?? null;
    $endsOn = $input['ends_on'] ?? null;
    if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) return ['ok' => false, 'reason' => 'announcement_dates'];

    if ($id !== null) {
        $st = db()->prepare('UPDATE ellsms_reseller_announcements SET title = ?, body = ?, severity = ?, audience = ?, active = ?, starts_on = ?, ends_on = ? WHERE id = ? AND owner_user_id = ?');
        $st->execute([$title, $body, $severity, $audience, $active, $startsOn, $endsOn, $id, $resellerId]);
        audit($actorId, 'reseller.announcement_updated', "reseller=#{$resellerId} #{$id}");
        return ['ok' => true, 'id' => $id, 'notified' => 0];
    }
    db()->prepare('INSERT INTO ellsms_reseller_announcements (owner_user_id, title, body, severity, audience, active, starts_on, ends_on, created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$resellerId, $title, $body, $severity, $audience, $active, $startsOn, $endsOn, $actorId]);
    $id = (int)db()->lastInsertId();
    $notified = 0;
    if ($active && ($startsOn === null || $startsOn <= customer_today())) {
        $recipients = $audience === 'direct' ? customer_child_ids($resellerId) : reseller_descendant_ids($resellerId);
        foreach ($recipients as $userId) {
            notification_insert_panel($userId, null, 'reseller.announcement', $title, mb_substr($body, 0, 900), '/dashboard', $severity);
            $notified++;
        }
        db()->prepare('UPDATE ellsms_reseller_announcements SET notified_count = ? WHERE id = ?')->execute([$notified, $id]);
    }
    audit($actorId, 'reseller.announcement_published', "reseller=#{$resellerId} #{$id} notified={$notified}");
    return ['ok' => true, 'id' => $id, 'notified' => $notified];
}

function reseller_announcement_delete(int $resellerId, int $id, int $actorId): void {
    db()->prepare('DELETE FROM ellsms_reseller_announcements WHERE id = ? AND owner_user_id = ?')->execute([$id, $resellerId]);
    audit($actorId, 'reseller.announcement_deleted', "reseller=#{$resellerId} #{$id}");
}

/**
 * The announcements $userId sees on its dashboard today: its parent's (any audience) and, from
 * resellers further up, those published to the whole sub-tree.
 *
 * @return list<array>
 */
function reseller_announcements_for_customer(int $userId): array {
    if ($userId <= 0 || !reseller_panel_available()) return [];
    $ancestors = customer_ancestor_ids($userId);
    if ($ancestors === []) return [];
    $today = customer_today();
    $st = db()->prepare('SELECT * FROM ellsms_reseller_announcements
                         WHERE owner_user_id IN (' . implode(',', array_fill(0, count($ancestors), '?')) . ') AND active = 1
                           AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)
                         ORDER BY created_at DESC, id DESC LIMIT 10');
    $st->execute([...$ancestors, $today, $today]);
    $parentId = $ancestors[0];
    return array_values(array_filter($st->fetchAll(), static fn(array $a): bool => (int)$a['owner_user_id'] === $parentId || $a['audience'] === 'tree'));
}

/* ==========================================================================
   Tickets
   ========================================================================== */

/**
 * Who answers a new ticket from $userId: the nearest reseller above it that takes its customers'
 * tickets, else null (platform support).
 */
function reseller_ticket_assignee(int $userId): ?int {
    if (!reseller_panel_available()) return null;
    $resellerId = reseller_nearest_ancestor($userId);
    return $resellerId !== null && reseller_settings($resellerId)['tickets_to_reseller'] ? $resellerId : null;
}

function reseller_ticket_count(int $resellerId, string $status): int {
    if (!reseller_panel_available()) return 0;
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_tickets WHERE assignee_user_id = ? AND status = ?');
    $st->execute([$resellerId, $status]);
    return (int)$st->fetchColumn();
}

/** A ticket the reseller answers, or null. */
function reseller_ticket_find(int $resellerId, int $ticketId): ?array {
    require_once __DIR__ . '/tickets.php';
    $ticket = ticket_find($ticketId);
    return $ticket !== null && (int)($ticket['assignee_user_id'] ?? 0) === $resellerId ? $ticket : null;
}

/** Hands a ticket back to platform support. */
function reseller_ticket_escalate(int $resellerId, int $ticketId, int $actorId): bool {
    require_once __DIR__ . '/tickets.php';
    $st = db()->prepare("UPDATE ellsms_tickets SET assignee_user_id = NULL, status = 'open' WHERE id = ? AND assignee_user_id = ?");
    $st->execute([$ticketId, $resellerId]);
    if ($st->rowCount() !== 1) return false;
    audit($actorId, 'reseller.ticket_escalated', "reseller=#{$resellerId} ticket=#{$ticketId}");
    ticket_notify_telegram("⬆️ تیکت #{$ticketId} توسط نماینده به پشتیبانی سامانه ارجاع شد.");
    return true;
}

/* ==========================================================================
   Sign-up requests through a reseller
   ========================================================================== */

function reseller_registration_count(int $resellerId): int {
    if (!reseller_panel_available()) return 0;
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_registration_requests WHERE reseller_user_id = ? AND state = 'pending_admin_approval'");
        $st->execute([$resellerId]);
        return (int)$st->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/** @return list<array> the reseller's sign-up requests, newest first */
function reseller_registrations(int $resellerId, string $state = ''): array {
    $where = 'reseller_user_id = ?';
    $params = [$resellerId];
    if ($state !== '') {
        $where .= ' AND state = ?';
        $params[] = $state;
    }
    $st = db()->prepare("SELECT id, first_name, last_name, mobile, email, username, account_type, company_name, state, national_id, created_at, mobile_verified_at, rejection_reason, created_user_id, activation_error
                         FROM ellsms_registration_requests WHERE {$where} ORDER BY id DESC LIMIT 200");
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * The reseller approves a sign-up request made through its link: the account is created in the
 * reseller's domain (registration_activate_account()), then attached under the reseller with its
 * sign-up defaults (customer type, tariff, expiry, welcome package).
 *
 * @return array{ok:bool, error?:string, user_id?:int}
 */
function reseller_registration_approve(int $resellerId, int $registrationId, string $nationalId, int $actorId): array {
    require_once __DIR__ . '/RegistrationActivation.php';
    $row = registration_request_get($registrationId);
    if (!$row || (int)($row['reseller_user_id'] ?? 0) !== $resellerId) return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if (!in_array($row['state'], ['pending_admin_approval', 'approved'], true)) return ['ok' => false, 'error' => 'این درخواست در وضعیت قابل تأیید نیست.'];
    $settings = reseller_settings($resellerId);
    $parent = customer_account($resellerId);
    $type = $settings['signup_customer_type'];
    if (!in_array($type, customer_allowed_child_types($parent), true)) return ['ok' => false, 'error' => customer_error_message('child_type_not_allowed')];
    if ($parent['max_subset_limit'] - customer_used_subset($resellerId) < 1) return ['ok' => false, 'error' => customer_error_message('subset_limit')];

    $result = registration_activate_account($registrationId, $actorId, [
        'national_id' => $nationalId,
        'domain_id'   => backend_user_domain_id($resellerId) ?? (int)(backend_list_domains()[0]['id'] ?? 0),
        'note'        => 'تأیید نماینده',
    ]);
    if (empty($result['ok'])) return ['ok' => false, 'error' => (string)($result['error'] ?? 'فعال‌سازی انجام نشد.')];
    return ['ok' => true, 'user_id' => (int)$result['user_id']];
}

/**
 * Called by registration_activate_account() once the account exists: a request made through a
 * reseller becomes that reseller's customer with its sign-up defaults. Idempotent.
 */
function reseller_registration_attach(array $registration, int $userId, int $actorId): array {
    $resellerId = (int)($registration['reseller_user_id'] ?? 0);
    if ($resellerId <= 0 || !reseller_panel_available() || !reseller_is_reseller($resellerId)) return ['ok' => true, 'skipped' => true];
    if (customer_account($userId)['exists']) return ['ok' => true, 'skipped' => true];
    $settings = reseller_settings($resellerId);
    $expiresOn = $settings['signup_expiry_days'] !== null
        ? (new DateTimeImmutable(customer_today()))->modify('+' . $settings['signup_expiry_days'] . ' days')->format('Y-m-d')
        : null;
    $parentExpiry = customer_account($resellerId)['expires_on'];
    if ($expiresOn !== null && $parentExpiry !== null && $expiresOn > $parentExpiry) $expiresOn = $parentExpiry;
    $attached = customer_attach_child($resellerId, $userId, $settings['signup_customer_type'], 0, CUSTOMER_TARIFF_BASE_BP, $expiresOn ?? $parentExpiry, $actorId);
    if (!$attached['ok']) {
        Logger::warning('reseller.registration_attach_failed', ['reseller_id' => $resellerId, 'user_id' => $userId, 'reason' => $attached['reason']]);
        return $attached;
    }
    $tariffId = $settings['signup_tariff_id'] ?? reseller_default_tariff_id($resellerId) ?? (reseller_tariffs_for($resellerId)[0]['id'] ?? null);
    if ($tariffId !== null) reseller_assign_tariff($resellerId, $userId, $tariffId, $actorId);
    if ($settings['signup_package_id'] !== null) {
        $order = reseller_package_assign($resellerId, $userId, $settings['signup_package_id'], $actorId, 'welcome:' . $userId);
        if (!$order['ok']) Logger::warning('reseller.welcome_package_failed', ['reseller_id' => $resellerId, 'user_id' => $userId, 'reason' => $order['reason']]);
    }
    notification_insert_panel($resellerId, null, 'reseller.registration', 'مشتری جدید از لینک ثبت‌نام', 'حساب «' . $registration['username'] . '» ساخته شد و زیرمجموعه‌ی شما است.', '/reseller/customers', 'success');
    return ['ok' => true];
}

/* ==========================================================================
   Messages
   ========================================================================== */

function reseller_error_message(string $reason): string {
    return [
        'not_migrated'         => 'جدول‌های پنل نمایندگی هنوز ساخته نشده‌اند (مهاجرت 2026_10_24_reseller_panel.sql).',
        'invalid_host'         => 'نام دامنه معتبر نیست؛ مثلاً sms.example.com را وارد کنید.',
        'host_reserved'        => 'این دامنه متعلق به خود سامانه است.',
        'host_taken'           => 'این دامنه را نماینده‌ی دیگری ثبت کرده است.',
        'signup_code_taken'    => 'کد لینک ثبت‌نام تکراری بود؛ دوباره ذخیره کنید.',
        'invalid_color'        => 'رنگ باید به شکل ‎#1a2b3c باشد.',
        'invalid_email'        => 'ایمیل پشتیبانی معتبر نیست.',
        'invalid_type'         => 'نوع حساب نامعتبر است.',
        'tariff_below_parent'  => 'تعرفه‌ی پیش‌فرض نمی‌تواند کمتر از تعرفه‌ی خودتان باشد.',
        'tariff_not_found'     => 'تعرفه پیدا نشد.',
        'package_not_found'    => 'بسته پیدا نشد.',
        'brand_name_required'  => 'برای برند اختصاصی، نام برند را وارد کنید.',
        'upload_failed'        => 'فایل لوگو دریافت نشد.',
        'logo_too_large'       => 'حجم لوگو حداکثر ۵۱۲ کیلوبایت است.',
        'logo_type'            => 'لوگو باید تصویر PNG، JPEG یا WebP باشد.',
        'storage'              => 'ذخیره‌ی فایل روی سرور ممکن نشد.',
        'staff_fields'         => 'همه‌ی فیلدها را پر کنید (رمز عبور حداقل ۸ نویسه و کد ملی ۱۰ رقم).',
        'staff_not_found'      => 'کارمند پیدا نشد.',
        'no_domain'            => 'دامنه‌ای برای ساخت حساب پیدا نشد؛ با پشتیبانی تماس بگیرید.',
        'backend_failed'       => 'ساخت حساب در سامانه‌ی مرکزی ناموفق بود.',
        'announcement_fields'  => 'عنوان و متن اطلاعیه را وارد کنید.',
        'announcement_dates'   => 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.',
        'not_on_this_host'     => 'این حساب از طریق این دامنه امکان ورود ندارد.',
    ][$reason] ?? customer_error_message($reason);
}
