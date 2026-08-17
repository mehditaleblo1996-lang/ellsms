<?php
/** expects: $pageTitle (string), $active (string nav key), $me (user array) */
$me = $me ?? require_login();
$nav = [
    'dashboard'  => ['/index.php',       'داشبورد',         '▦'],
    'new_send'   => ['/new-send.php',    'پنل جدید ارسال',  '🆕'],
    'send'       => ['/send.php',        'ارسال پیامک',     '➤'],
    'p2p'        => ['/p2p-send.php',    'نظیر به نظیر',    '⇄'],
    'smart'      => ['/smart-send.php',  'پیامک هوشمند',    '✦'],
    'schedules'  => ['/schedules.php',   'زمان‌بندی‌شده',    '◷'],
    'autoreply'  => ['/autoreply.php',   'منشی پیامک',      '🤖'],
    'reports'    => ['/reports.php',     'گزارش ارسال',     '≣'],
    'inbox'      => ['/inbox.php',       'صندوق دریافت',    '✉'],
    'contacts'   => ['/contacts.php',    'مخاطبین',         '☰'],
    'blacklist'  => ['/blacklist.php',   'لیست سیاه',        '🚫'],
    'tickets'    => ['/tickets.php',     'پشتیبانی',        '🎫'],
    'buy_credit' => ['/buy-credit.php',  'خرید اعتبار',      '💳'],
];
// Phase 12 — org-scoped integration nav (API keys/webhooks). Shown only when the current
// organization membership actually carries the corresponding VIEW permission (owner/admin by
// default, see app/rbac.php's role_permissions() — member does not) so an ordinary member never
// sees a link that would just 403 on click; every OTHER nav item above is granted to every role by
// default today, which is why none of them needed this same conditional treatment.
$integrationNav = [];
$navOrg = current_organization();
if ($navOrg && membership_has_permission($navOrg, Permissions::API_KEYS_VIEW)) {
    $integrationNav['api_keys'] = ['/api-keys.php', 'کلیدهای API', '🔑'];
}
if ($navOrg && membership_has_permission($navOrg, Permissions::WEBHOOKS_VIEW)) {
    $integrationNav['webhooks'] = ['/webhooks.php', 'وب‌هوک‌ها', '🔗'];
}
// Phase 13 — the organization's own subscription/usage page. Shown to anyone with BILLING_VIEW
// (owner/admin by default); BILLING_MANAGE additionally controls whether the actions on that page
// are available, checked there rather than here.
if ($navOrg && membership_has_permission($navOrg, Permissions::BILLING_VIEW)) {
    $integrationNav['billing'] = ['/billing.php', 'اشتراک و مصرف', '📦'];
}
$adminNav = [
    'users'             => ['/users.php',             'کاربران',        '👤'],
    'kyc_review'        => ['/kyc-review.php',        'بررسی احراز هویت', '🪪'],
    'analytics'         => ['/analytics.php',          'آمار تفصیلی',    '📊'],
    'numbers'           => ['/numbers.php',            'شماره‌ها',        '📞'],
    'number_categories' => ['/number-categories.php',  'دسته‌های شماره',  '🗂'],
    'slides'            => ['/slides.php',             'اسلایدر صفحه‌ی اصلی', '🖼'],
    'pricing'           => ['/pricing.php',            'بسته‌های قیمتی',  '🏷'],
    'sms_pricing'       => ['/sms-pricing.php',        'تعرفه‌ی پیامک',   '💱'],
    'sms_gateways'      => ['/sms-gateways.php',       'درگاه‌های پیامک', '🔌'],
    'guide_admin'       => ['/guide-admin.php',        'راهنمای استفاده', '📘'],
    'billing_admin'     => ['/billing-admin.php',       'مدیریت اشتراک‌ها', '📦'],
    'settings'          => ['/settings.php',            'تنظیمات',        '⚙'],
];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'ELLSMS') ?> — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/index.php">
      <img src="/assets/img/logo.png" alt="ELLSMS">
    </a>
    <nav>
      <div class="nav-label">پیام‌رسانی</div>
      <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
        <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
          <span class="nav-icon"><?= $icon ?></span><?= $label ?>
        </a>
      <?php endforeach; ?>
      <?php if ($integrationNav): ?>
        <div class="nav-label">یکپارچه‌سازی</div>
        <?php foreach ($integrationNav as $key => [$href, $label, $icon]): ?>
          <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><?= $label ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($me['role'] === 'admin'): ?>
        <div class="nav-label">مدیریت</div>
        <?php foreach ($adminNav as $key => [$href, $label, $icon]): ?>
          <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><?= $label ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
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
            اعتبار: <strong class="ltr"><?= to_persian_digits(number_format((int)$me['credit'])) ?></strong>
          </span>
        <?php endif; ?>
        <a class="user-chip" href="/profile.php" title="حساب کاربری و رمز عبور">
          <?= e($me['full_name'] ?: $me['username']) ?><?= $me['role'] === 'admin' ? ' · مدیر' : '' ?>
        </a>
        <form method="post" action="/logout.php" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost">خروج</button>
        </form>
      </div>
    </header>

    <main class="content">
      <?php
      /*
       * Support-impersonation banner (docs/admin-impersonation.md, STEP 18). Rendered on EVERY
       * authenticated page, above everything else, because the single worst failure mode of this
       * feature is an operator forgetting which account they are looking at. Deliberately text and
       * a colour, not an icon — an icon is missable, and this must not be.
       *
       * It carries the ONLY control in the panel that uses the preserved real-actor context; every
       * other part of the page behaves as the target user.
       */
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
            <button type="submit" class="btn btn-sm btn-primary">بازگشت به پنل مدیریت</button>
          </form>
        </div>
      <?php endif; ?>
      <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
