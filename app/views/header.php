<?php
/** expects: $pageTitle (string), $active (string nav key), $me (user array) */
$me = $me ?? require_login();
require_once __DIR__ . '/../NotificationCenter.php';
$nav = [
    'dashboard'     => ['/index.php',         'داشبورد',         '▦'],
    'notifications' => ['/notifications.php', 'اعلان‌ها',         '🔔'],
    'guide'         => ['/guide.php',         'راهنمای کاربران',  '📘'],
];
if ($me['role'] !== 'admin' && setting('onboarding_enabled', '1') !== '0') {
    $nav['onboarding'] = ['/onboarding.php', 'شروع کار', '✓'];
}
$nav += [
    'new_send'   => ['/new-send.php',    'پنل جدید ارسال',  '🆕'],
    'send'       => ['/send.php',        'ارسال پیامک',     '➤'],
    'p2p'        => ['/p2p-send.php',    'نظیر به نظیر',    '⇄'],
    'smart'      => ['/smart-send.php',  'پیامک هوشمند',    '✦'],
    'schedules'  => ['/schedules.php',   'زمان‌بندی‌شده',    '◷'],
    'autoreply'  => ['/autoreply.php',   'منشی پیامک',      '🤖'],
    'reports'    => ['/reports.php',     'گزارش ارسال',     '≣'],
    'reports_bulk' => ['/reports-bulk.php', 'گزارش ارسال حجیم', '≣'],
    'inbox'      => ['/inbox.php',       'صندوق دریافت',    '✉'],
    'contacts'   => ['/contacts.php',    'مخاطبین',         '☰'],
    'blacklist'  => ['/blacklist.php',   'لیست سیاه',        '🚫'],
    'line_optouts' => ['/line-optouts.php', 'لغو عضویت (۱۱)', '⛔'],
    'tickets'    => ['/tickets.php',     'پشتیبانی',        '🎫'],
    'buy_credit' => ['/buy-credit.php',  'خرید اعتبار',      '💳'],
    'invoices'   => ['/invoices.php',    'فاکتورها',        '🧾'],
];
// #42 — the Bale channel's message record, shown once the channel is set up (admins always, to set it up).
if ($me['role'] === 'admin' || bale_configured()) {
    $nav['bale'] = ['/bale-messages.php', 'پیام‌رسان بله', '💬'];
}
// #43 — regional bulk through Vesal, shown once it is set up (admins always, to set it up).
if ($me['role'] === 'admin' || regional_bulk_configured()) {
    $nav['regional_bulk'] = ['/regional-bulk.php', 'ارسال منطقه‌ای', '🗺️'];
}
$integrationNav = [];
$navOrg = current_organization();
if ($navOrg && membership_has_permission($navOrg, Permissions::API_KEYS_VIEW)) {
    $integrationNav['api_keys'] = ['/api-keys.php', 'کلیدهای API', '🔑'];
}
// WooCommerce plugin, SDKs and their guide — for whoever can see API keys (they are what the tools use).
if ($me['role'] === 'admin' || ($navOrg && membership_has_permission($navOrg, Permissions::API_KEYS_VIEW))) {
    $integrationNav['integrations'] = ['/integrations.php', 'افزونه ووکامرس و SDK', '🧩'];
}
// #45 — a customer whose account has a database connection sees its read-only health card.
if ($me['role'] !== 'admin') {
    try {
        $navRdb = db()->prepare('SELECT 1 FROM ellsms_remote_db_connections WHERE user_id = ? LIMIT 1');
        $navRdb->execute([(int)$me['id']]);
        if ($navRdb->fetchColumn()) $integrationNav['remote_db'] = ['/remote-db.php', 'اتصال به دیتابیس', '🗄'];
    } catch (PDOException) {
        // 2026_10_06_remote_db_connections.sql not applied yet: no entry.
    }
}
if ($navOrg && membership_has_permission($navOrg, Permissions::WEBHOOKS_VIEW)) {
    $integrationNav['webhooks'] = ['/webhooks.php', 'وب‌هوک‌ها', '🔗'];
}
// Per-line MO / delivery-report forwarding to a URL (app/NumberForwarding.php): for admins, and for
// any customer with a line of their own to forward.
if (number_forward_available() && ($me['role'] === 'admin' || allowed_originators($me) !== [])) {
    $integrationNav['number_forwards'] = ['/number-forwards.php', 'فوروارد پیامک به URL', '📡'];
}
if ($navOrg && membership_has_permission($navOrg, Permissions::BILLING_VIEW)) {
    $integrationNav['billing'] = ['/billing.php', 'اشتراک و مصرف', '📦'];
}
// Resellers and organizations manage their sub-accounts (app/CustomerHierarchy.php).
if ($me['role'] !== 'admin') {
    $navAccount = customer_account((int)$me['id']);
    if (customer_can_have_children($navAccount) || customer_child_ids((int)$me['id']) !== []) {
        $nav['subaccounts'] = ['/subaccounts.php', customer_type_label($navAccount) === 'سازمانی' ? 'زیرسازمان‌ها' : 'زیرمجموعه‌ها', '👥'];
    }
    // Menus closed for this user (app/MenuAccess.php) are left out; their pages answer 403 too.
    $nav = array_filter($nav + $integrationNav, static fn(string $key): bool => menu_access_allows($me, $key), ARRAY_FILTER_USE_KEY);
} else {
    $nav += $integrationNav;
}
$navGroups = menu_group_items($nav, menu_groups());
$adminNav = [
    'users'                 => ['/users.php',                 'کاربران',            '👤'],
    'menu_roles'            => ['/menu-roles.php',            'نقش‌های دسترسی منو', '🧭'],
    'reseller_profits'      => ['/reseller-profits.php',      'سود نمایندگان',       '💹'],
    'user_send_policies'    => ['/user-send-policies.php',    'محدودیت ارسال کاربران', '🛡'],
    'registration_requests' => ['/registration-requests.php', 'درخواست‌های ثبت‌نام', '📝'],
    'kyc_review'            => ['/kyc-review.php',            'بررسی احراز هویت',   '🪪'],
    'kyc_gates'             => ['/kyc-gates.php',             'محدودیت‌های KYC',    '🔐'],
    'analytics'             => ['/analytics.php',             'آمار تفصیلی',        '📊'],
    'logs'                  => ['/logs.php',                  'لاگ فعالیت‌ها',       '📜'],
    'numbers'               => ['/numbers.php',               'شماره‌ها',            '📞'],
    'number_categories'     => ['/number-categories.php',     'دسته‌های شماره',      '🗂'],
    'landing_videos'        => ['/landing-videos.php',        'ویدیوهای صفحه‌ی اصلی', '🎬'],
    'pricing'               => ['/pricing.php',               'بسته‌های قیمتی',      '🏷'],
    'price_quote'           => ['/price-quote.php',           'فاکتور / لیست قیمت', '🧾'],
    'sms_pricing'           => ['/sms-pricing.php',           'تعرفه‌ی پیامک',       '💱'],
    'sms_gateways'          => ['/sms-gateways.php',          'درگاه‌های پیامک',     '🔌'],
    'remote_db'             => ['/remote-db.php',             'اتصال به دیتابیس مشتری', '🗄'],
    'prohibited_words'      => ['/prohibited-words.php',      'کلمات ممنوع',         '🚷'],
    'queue_cancellation'    => ['/admin/queue/cancellation',  'لغو صف ارسال',        '🛑'],
    'alerts'                => ['/admin/alerts',               'هشدارها',             '🔔'],
    'bulk_archive'          => ['/admin/bulk-archive',        'آرشیو شش‌ماهه پیام‌ها', '🗄'],
    'sms_gateway_clone'     => ['/sms-gateway-clone.php',     'کپی کامل درگاه',      '⧉'],
    'guide_admin'           => ['/guide-admin.php',           'راهنمای استفاده',     '📘'],
    'billing_admin'         => ['/billing-admin.php',         'مدیریت اشتراک‌ها',    '📦'],
    'financial_admin'       => ['/financial-admin.php',       'گزارش مالی',          '🧾'],
    'settings'              => ['/settings.php',              'تنظیمات',             '⚙'],
];
// Admin-only groups, shown under the customer groups (menu_groups()).
$adminNavGroups = menu_group_items($adminNav, [
    'admin_users'    => ['کاربران و نمایندگان', ['users', 'registration_requests', 'menu_roles', 'reseller_profits', 'user_send_policies']],
    'admin_kyc'      => ['احراز هویت',          ['kyc_review', 'kyc_gates']],
    'admin_numbers'  => ['شماره‌ها و درگاه‌ها',  ['numbers', 'number_categories', 'sms_gateways', 'sms_gateway_clone', 'remote_db']],
    'admin_finance'  => ['قیمت‌گذاری و مالی',    ['pricing', 'sms_pricing', 'price_quote', 'billing_admin', 'financial_admin']],
    'admin_sending'  => ['کنترل ارسال',          ['prohibited_words', 'queue_cancellation', 'bulk_archive']],
    'admin_monitor'  => ['نظارت و گزارش',        ['analytics', 'logs', 'alerts']],
    'admin_content'  => ['محتوا و تنظیمات',      ['landing_videos', 'guide_admin', 'settings']],
]);
$sidebarGroups = $me['role'] === 'admin' ? $navGroups + $adminNavGroups : $navGroups;
$notificationUnread = notification_unread_count((int)$me['id']);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'ELLSMS') ?> — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
<link rel="stylesheet" href="/assets/css/panel-layout-fixes.css">
</head>
<body>
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<div class="shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/index.php">
      <img src="/assets/img/logo.png" alt="ELLSMS">
    </a>
    <nav>
      <?php foreach ($sidebarGroups as $groupId => [$groupLabel, $groupItems]): ?>
        <?php $groupActive = array_key_exists($active ?? '', $groupItems); ?>
        <details class="nav-group<?= $groupActive ? ' has-active' : '' ?>" data-group="<?= e($groupId) ?>"<?= $groupActive ? ' open' : '' ?>>
          <summary class="nav-label"><?= e($groupLabel) ?><span class="nav-chevron" aria-hidden="true"></span></summary>
          <?php foreach ($groupItems as $key => [$href, $label, $icon]): ?>
            <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon"><?= $icon ?></span><?= $label ?>
              <?php if ($key === 'notifications' && $notificationUnread > 0): ?><span class="badge badge-pending" style="margin-inline-start:auto"><?= to_persian_digits((string)min(99,$notificationUnread)) ?><?= $notificationUnread > 99 ? '+' : '' ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
    </nav>
    <script>
    // Sidebar groups remember being opened or closed (per browser); the current page's group stays open.
    (function () {
      var key = 'ellsms.navGroups', state = {};
      try { state = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) {}
      document.querySelectorAll('#sidebar .nav-group').forEach(function (g) {
        var id = g.getAttribute('data-group');
        if (!g.classList.contains('has-active') && typeof state[id] === 'boolean') g.open = state[id];
        g.addEventListener('toggle', function () {
          state[id] = g.open;
          try { localStorage.setItem(key, JSON.stringify(state)); } catch (e) {}
        });
      });
    })();
    </script>
    <div class="sidebar-foot">ELLSMS v<?= e(app_version()) ?><?php if (app_env() !== 'production'): ?> · <?= e(strtoupper(app_env())) ?><?php endif; ?></div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" id="menuToggle" aria-label="باز کردن منو" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
      <div class="topbar-right">
        <?php if ($me['role'] !== 'admin'): ?>
          <span class="credit-pill" title="اعتبار باقی‌مانده">
            اعتبار: <strong class="ltr"><?= to_persian_digits(number_format((int)$me['credit'])) ?></strong> ریال
          </span>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/notifications.php" title="اعلان‌ها" style="position:relative">
          🔔<?php if ($notificationUnread > 0): ?> <strong><?= to_persian_digits((string)min(99,$notificationUnread)) ?><?= $notificationUnread > 99 ? '+' : '' ?></strong><?php endif; ?>
        </a>
        <a class="user-chip" href="/profile.php" title="حساب کاربری و رمز عبور">
          <?= e($me['full_name'] ?: $me['username']) ?><?= $me['role'] === 'admin' ? ' · مدیر' : '' ?>
        </a>
        <form method="post" action="/logout.php" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost">خروج</button>
        </form>
      </div>
    </header>

    <main id="main" tabindex="-1" class="content content-<?= e($active ?? 'page') ?>">
      <?php
      $impersonationBanner = is_impersonating() ? impersonation_banner_context() : null;
      ?>
      <?php if ($impersonationBanner): ?>
        <div class="flash flash-error" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
          <span>
            <strong>حالت پشتیبانی فعال است</strong> — شما در حال مشاهده حساب
            «<?= e(trim(($impersonationBanner['organization_name'] !== '' ? $impersonationBanner['organization_name'] . ' / ' : '') . $impersonationBanner['target_username'])) ?>»
            هستید. عملیات حساس غیرفعال است و این نشست ممیزی می‌شود.
            <span class="hint" style="display:inline">(<?= to_persian_digits((string)(int)ceil($impersonationBanner['expires_in'] / 60)) ?> دقیقه باقی‌مانده)</span>
          </span>
          <form method="post" action="/impersonate.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="exit">
            <button type="submit" class="btn btn-sm btn-primary"><?= $impersonationBanner['actor_is_admin'] ? 'بازگشت به پنل مدیریت' : 'بازگشت به پنل خودم' ?></button>
          </form>
        </div>
      <?php endif; ?>
      <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>