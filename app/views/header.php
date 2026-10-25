<?php
/** expects: $pageTitle (string), $active (string nav key), $me (user array) */
$me = $me ?? require_login();
require_once __DIR__ . '/panel-icons.php';
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
    'financial_log' => ['/financial-log.php', 'تاریخچه مالی', '📒'],
];
// #42 — the Bale channel's message record, shown once the channel is set up (admins always, to set it up).
if ($me['role'] === 'admin' || bale_configured()) {
    $nav['bale'] = ['/bale-messages.php', 'پیام‌رسان بله', '💬'];
}
// Voice messages through ZarinCall, shown once the service is set up (admins always, to follow sends).
if ($me['role'] === 'admin' || voice_configured()) {
    $nav['voice'] = ['/voice.php', 'پیام صوتی', '🔊'];
    $nav['voice_files'] = ['/voice-files.php', 'فایل‌های صوتی', '🎙'];
}
// #43 — regional bulk through Vesal, shown once it is set up (admins always, to set it up).
if ($me['role'] === 'admin' || regional_bulk_configured()) {
    $nav['regional_bulk'] = ['/regional-bulk.php', 'ارسال منطقه‌ای', '🗺️'];
}
// Aimed bulk from ELLSMS's own subscriber bank, shown once enabled (admins always, to set it up).
if ($me['role'] === 'admin' || aimed_bulk_enabled()) {
    $nav['aimed_bulk'] = ['/aimed-bulk.php', 'ارسال با کد پستی', '📮'];
}
$integrationNav = [];
$navOrg = current_organization();
if ($navOrg && membership_has_permission($navOrg, Permissions::API_KEYS_VIEW)) {
    $integrationNav['api_keys'] = ['/api-keys.php', 'کلیدهای API', '🔑'];
}
// Shared OTP templates (app/SharedOtp.php), once the service is on; admins manage it under their own menu.
if ($me['role'] !== 'admin' && shared_otp_configured()) {
    $integrationNav['otp_templates'] = ['/otp-templates.php', 'کد تایید اشتراکی', '🔐'];
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
// Resellers and organizations manage their sub-accounts (app/CustomerHierarchy.php); a reseller in
// its own panel (app/ResellerPanel.php).
if ($me['role'] !== 'admin') {
    $navAccount = customer_account((int)$me['id']);
    if (customer_can_have_children($navAccount) || customer_child_ids((int)$me['id']) !== []) {
        $nav['subaccounts'] = ['/subaccounts.php', customer_type_label($navAccount) === 'سازمانی' ? 'زیرسازمان‌ها' : 'زیرمجموعه‌ها', '👥'];
    }
    // Follows the «زیرمجموعه‌ها» menu: closing it closes the panel too (app/MenuAccess.php).
    if ($navAccount['customer_type'] === 'reseller' && reseller_panel_available() && menu_access_allows($me, 'subaccounts')) {
        $nav['reseller_panel'] = ['/reseller', 'پنل نمایندگی', '👥'];
    }
    // A reseller's customer buys its reseller's packages and redeems its codes.
    if ($navAccount['parent_user_id'] !== null && reseller_panel_available() && reseller_is_reseller((int)$navAccount['parent_user_id'])
        && menu_access_allows($me, 'buy_credit')) {
        $nav['reseller_packages'] = ['/billing/packages', 'بسته‌های نماینده', '🎁'];
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
    'voice_settings'        => ['/voice-settings.php',        'سرویس پیام صوتی',     '🔊'],
    'shared_otp'            => ['/otp-templates.php',         'کد تایید اشتراکی',    '🔐'],
    'remote_db'             => ['/remote-db.php',             'اتصال به دیتابیس مشتری', '🗄'],
    'prohibited_words'      => ['/prohibited-words.php',      'کلمات ممنوع',         '🚷'],
    'queue_cancellation'    => ['/admin/queue/cancellation',  'لغو صف ارسال',        '🛑'],
    'queue_monitor'         => ['/admin/queue/monitor',       'مانیتورینگ صف‌ها',    '📈'],
    'alerts'                => ['/admin/alerts',               'هشدارها',             '🔔'],
    'bulk_archive'          => ['/admin/bulk-archive',        'آرشیو شش‌ماهه پیام‌ها', '🗄'],
    'sms_gateway_clone'     => ['/sms-gateway-clone.php',     'کپی کامل درگاه',      '⧉'],
    'guide_admin'           => ['/guide-admin.php',           'راهنمای استفاده',     '📘'],
    'billing_admin'         => ['/billing-admin.php',         'مدیریت اشتراک‌ها',    '📦'],
    'financial_admin'       => ['/financial-admin.php',       'گزارش مالی',          '🧾'],
    'manual_payments'       => ['/manual-payments.php',       'پرداخت‌های دستی',     '💵'],
    'settings'              => ['/settings.php',              'تنظیمات',             '⚙'],
];
// Admin-only groups, shown under the customer groups (menu_groups()).
$adminNavGroups = menu_group_items($adminNav, [
    'admin_users'    => ['کاربران و نمایندگان', ['users', 'registration_requests', 'menu_roles', 'reseller_profits', 'user_send_policies']],
    'admin_kyc'      => ['احراز هویت',          ['kyc_review', 'kyc_gates']],
    'admin_numbers'  => ['شماره‌ها و درگاه‌ها',  ['numbers', 'number_categories', 'sms_gateways', 'sms_gateway_clone', 'voice_settings', 'remote_db']],
    'admin_finance'  => ['قیمت‌گذاری و مالی',    ['pricing', 'sms_pricing', 'price_quote', 'billing_admin', 'financial_admin', 'manual_payments']],
    'admin_sending'  => ['کنترل ارسال',          ['shared_otp', 'prohibited_words', 'queue_cancellation', 'bulk_archive']],
    'admin_monitor'  => ['نظارت و گزارش',        ['queue_monitor', 'analytics', 'logs', 'alerts']],
    'admin_content'  => ['محتوا و تنظیمات',      ['landing_videos', 'guide_admin', 'settings']],
]);
$sidebarGroups = $me['role'] === 'admin' ? $navGroups + $adminNavGroups : $navGroups;
// On a reseller's own host the panel carries the reseller's brand (app/ResellerPanel.php).
$brand = current_brand();
$notificationUnread = notification_unread_count((int)$me['id']);
try {
    $notificationPreview = notification_list((int)$me['id'], 0, 5);
} catch (Throwable) {
    $notificationPreview = [];
}
$creditLevel = $me['role'] !== 'admin'
    ? profile_credit_level_for((int)$me['credit'], $navOrg ? (int)$navOrg['organization_id'] : 0)
    : '';
$supportHref = isset($nav['tickets']) ? $nav['tickets'][0] : null;
$nowTehran = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
$todayLabel = JALALI_WEEKDAYS[((int)$nowTehran->format('w') + 1) % 7] . ' ' . jdate_long($nowTehran->format('Y-m-d'));
$userLabel = $me['full_name'] ?: $me['username'];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? $brand['name']) ?> — <?= e($brand['name']) ?></title>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/panel-layout-fixes.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/panel.css')) ?>">
<?php foreach (($extraStyles ?? []) as $extraStyle): ?><link rel="stylesheet" href="<?= e(asset_url((string)$extraStyle)) ?>">
<?php endforeach; ?>
<script src="<?= e(asset_url('/assets/js/panel.js')) ?>" defer></script>
<?= brand_color_style($brand) ?>
</head>
<body class="panel">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<div class="shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar" aria-label="منوی پنل">
    <div class="sidebar-head">
    <a class="brand" href="/index.php">
      <?php if ($brand['logo'] !== ''): ?><img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>"><?php else: ?><strong><?= e($brand['name']) ?></strong><?php endif; ?>
    </a>
    <button type="button" class="sidebar-close icon-button" id="sidebarClose" aria-label="بستن منو"><?= panel_icon('close') ?></button>
    </div>
    <div class="sidebar-workspace"><?= $me['role'] === 'admin' ? 'فضای مدیریت' : 'فضای کار شما' ?></div>
    <div class="nav-search" hidden>
      <?= panel_icon('search') ?>
      <label class="sr-only" for="navSearch">جست‌وجو در منوها</label>
      <input id="navSearch" type="search" placeholder="جست‌وجو در منوها…" autocomplete="off" aria-controls="panelNav">
    </div>
    <nav id="panelNav" aria-label="بخش‌های پنل">
      <?php foreach ($sidebarGroups as $groupId => [$groupLabel, $groupItems]): ?>
        <?php $groupActive = array_key_exists($active ?? '', $groupItems); ?>
        <details class="nav-group<?= $groupActive ? ' has-active' : '' ?>" data-group="<?= e($groupId) ?>"<?= $groupActive ? ' open' : '' ?>>
          <summary class="nav-label"><span><?= e($groupLabel) ?></span><span class="nav-group-count" aria-hidden="true"><?= to_persian_digits((string)count($groupItems)) ?></span><span class="nav-chevron" aria-hidden="true"></span></summary>
          <div class="nav-group-items">
          <?php foreach ($groupItems as $key => [$href, $label, $icon]): ?>
            <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon"><?= panel_icon($key) ?></span><span class="nav-text"><?= e($label) ?></span>
              <?php if ($key === 'notifications' && $notificationUnread > 0): ?><span class="badge badge-pending" style="margin-inline-start:auto"><?= to_persian_digits((string)min(99,$notificationUnread)) ?><?= $notificationUnread > 99 ? '+' : '' ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </nav>
    <p class="nav-search-empty" id="navSearchEmpty" hidden role="status">منویی پیدا نشد؛ عبارت دیگری بنویسید.</p>
    <div class="sidebar-foot">Datis Rayan Gostar Iranian v<?= e(app_version()) ?><?php if (app_env() !== 'production'): ?> · <?= e(strtoupper(app_env())) ?><?php endif; ?></div>
  </aside>

  <div class="main">
    <header class="topbar" data-scope="<?= (int)$me['id'] ?>">
      <div class="topbar-row">
        <button class="menu-toggle" type="button" id="menuToggle" aria-label="باز کردن منو" aria-controls="sidebar" aria-expanded="false">
          <span></span><span></span><span></span>
        </button>
        <div class="topbar-status">
          <?php if ($me['role'] !== 'admin'): ?>
            <div class="topbar-credit<?= $creditLevel !== '' ? ' is-' . $creditLevel : '' ?>" id="topbarCredit" title="<?= $creditLevel === 'low' ? 'اعتبار شما کم است' : 'اعتبار باقی‌مانده' ?>">
              <span class="topbar-credit-icon" aria-hidden="true"><?= panel_icon('wallet') ?></span>
              <span class="topbar-credit-amount">
                <span class="sr-only">اعتبار باقی‌مانده:</span>
                <strong id="topbarCreditValue"><?= to_persian_digits(number_format((int)$me['credit'])) ?></strong> <small>ریال</small>
                <span class="sr-only" id="topbarCreditLow"<?= $creditLevel === 'low' ? '' : ' hidden' ?>>(اعتبار کم است)</span>
              </span>
              <button type="button" class="topbar-mini" id="topbarCreditRefresh" title="به‌روزرسانی اعتبار" aria-label="به‌روزرسانی اعتبار" hidden><?= panel_icon('refresh') ?></button>
              <?php if (isset($nav['buy_credit'])): ?>
                <a class="topbar-mini topbar-mini-primary" href="<?= e($nav['buy_credit'][0]) ?>" title="خرید اعتبار" aria-label="خرید اعتبار"><?= panel_icon('plus') ?></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="topbar-clock" id="topbarClock" data-now="<?= $nowTehran->getTimestamp() * 1000 ?>">
            <span class="topbar-clock-icon" aria-hidden="true"><?= panel_icon('calendar') ?></span>
            <span id="topbarToday"><?= e($todayLabel) ?></span>
            <time class="topbar-time" id="topbarTime"><?= to_persian_digits($nowTehran->format('H:i:s')) ?></time>
          </div>
        </div>

        <div class="topbar-actions">
          <details class="topbar-menu" id="bookmarksMenu" hidden>
            <summary class="icon-button" title="نشانک‌ها" aria-label="نشانک‌ها"><?= panel_icon('bookmark') ?></summary>
            <div class="topbar-pop">
              <div class="topbar-pop-head">
                <strong>نشانک‌ها</strong>
                <button type="button" class="btn btn-sm btn-ghost" id="bookmarkToggle" aria-pressed="false">نشانک کردن این صفحه</button>
              </div>
              <ul class="topbar-pop-list" id="bookmarkList"></ul>
              <p class="topbar-pop-empty" id="bookmarkEmpty">هنوز نشانکی ندارید. صفحه‌هایی را که زیاد سر می‌زنید نشانک کنید تا از اینجا سریع بازشان کنید.</p>
            </div>
          </details>

          <?php if ($supportHref !== null): ?>
            <a class="icon-button" href="<?= e($supportHref) ?>" title="پشتیبانی" aria-label="پشتیبانی"><?= panel_icon('headset') ?></a>
          <?php endif; ?>

          <details class="topbar-menu" id="notificationsMenu">
            <summary class="icon-button notification-button" title="اعلان‌ها" aria-label="اعلان‌ها<?= $notificationUnread > 0 ? '، ' . to_persian_digits((string)$notificationUnread) . ' خوانده‌نشده' : '' ?>">
              <?= panel_icon('bell') ?><?php if ($notificationUnread > 0): ?><strong><?= to_persian_digits((string)min(99,$notificationUnread)) ?><?= $notificationUnread > 99 ? '+' : '' ?></strong><?php endif; ?>
            </summary>
            <div class="topbar-pop topbar-pop-wide">
              <div class="topbar-pop-head">
                <strong>اعلان‌ها</strong>
                <?php if ($notificationUnread > 0): ?>
                  <form method="post" action="/notifications.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="read_all">
                    <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '')) ?>">
                    <button type="submit" class="btn btn-sm btn-ghost">خواندن همه</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if ($notificationPreview): ?>
                <ul class="topbar-pop-list">
                  <?php foreach ($notificationPreview as $note): ?>
                    <li>
                      <a class="topbar-note<?= $note['read_at'] ? '' : ' is-unread' ?>" href="<?= e((string)$note['action_url'] !== '' ? (string)$note['action_url'] : '/notifications.php') ?>">
                        <strong><?= e($note['title']) ?></strong>
                        <?php if ((string)$note['body'] !== ''): ?><span class="topbar-note-body"><?= e(mb_strimwidth((string)$note['body'], 0, 110, '…')) ?></span><?php endif; ?>
                        <span class="topbar-note-time"><?= jdate_from_utc((string)$note['created_at']) ?></span>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="topbar-pop-empty">هنوز اعلانی ندارید.</p>
              <?php endif; ?>
              <a class="topbar-pop-foot" href="/notifications.php">مشاهده‌ی همه‌ی اعلان‌ها ←</a>
            </div>
          </details>

          <details class="topbar-menu topbar-user" id="userMenu">
            <summary class="user-chip" title="حساب کاربری" aria-label="حساب کاربری: <?= e($userLabel) ?>">
              <span class="user-avatar" aria-hidden="true"><?= e(mb_substr($userLabel, 0, 1)) ?></span><span class="user-name"><?= e($userLabel) ?></span><span class="user-chevron" aria-hidden="true"><?= panel_icon('chevron-down') ?></span>
            </summary>
            <div class="topbar-pop topbar-pop-user">
              <dl class="topbar-user-info">
                <div><dt>نام کاربری</dt><dd class="ltr"><?= e($me['username']) ?></dd></div>
                <div><dt>ایمیل</dt><dd class="ltr"><?= e((string)($me['email'] ?? '') !== '' ? (string)$me['email'] : '—') ?></dd></div>
              </dl>
              <a class="topbar-pop-item" href="/profile.php"><?= panel_icon('user') ?><span>پروفایل کاربری</span></a>
              <a class="topbar-pop-item" href="/profile.php#security"><?= panel_icon('lock') ?><span>تغییر کلمه‌ی عبور</span></a>
              <a class="topbar-pop-item" id="accountSecurityShortcut" href="/account/security/"><?= panel_icon('shield') ?><span>امنیت حساب و MFA</span></a>
              <?php if ($navOrg): ?>
                <a class="topbar-pop-item" href="/profile.php#alerts"><?= panel_icon('bell') ?><span>هشدار اعتبار کم</span></a>
              <?php endif; ?>
              <form method="post" action="/logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="topbar-logout"><?= panel_icon('logout') ?><span>خروج از حساب</span></button>
              </form>
            </div>
          </details>
        </div>
      </div>

      <div class="page-tabs" id="pageTabs" hidden>
        <ul class="page-tabs-list" id="pageTabsList" aria-label="صفحه‌های باز"></ul>
        <details class="topbar-menu page-tabs-more">
          <summary class="icon-button" title="مدیریت تب‌ها" aria-label="مدیریت تب‌ها"><?= panel_icon('more') ?></summary>
          <div class="topbar-pop">
            <button type="button" class="topbar-pop-item" data-tabs-close="others"><?= panel_icon('close') ?><span>بستن سایر تب‌ها</span></button>
            <button type="button" class="topbar-pop-item" data-tabs-close="all"><?= panel_icon('close') ?><span>بستن همه‌ی تب‌ها</span></button>
          </div>
        </details>
        <template id="topbarIcons"><span data-icon="refresh"><?= panel_icon('refresh') ?></span><span data-icon="close"><?= panel_icon('close') ?></span></template>
      </div>
    </header>

    <main id="main" tabindex="-1" class="content content-<?= e($active ?? 'page') ?>">
      <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
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
