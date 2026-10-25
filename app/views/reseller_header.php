<?php
/**
 * The reseller panel's chrome (app/ResellerPanel.php): the panel shell with the reseller's own
 * sidebar. Expects $pageTitle, $active (a reseller nav key), $me and $ctx (reseller_context()).
 * Closed with app/views/footer.php like every panel page.
 */
require_once __DIR__ . '/panel-icons.php';
require_once __DIR__ . '/../NotificationCenter.php';

$resellerNav = [
    'reseller_dashboard'     => ['/reseller',               'داشبورد نمایندگی',      null],
    'reseller_customers'     => ['/reseller/customers',     'مشتریان',               'customers.view'],
    'reseller_registrations' => ['/reseller/registrations', 'درخواست‌های ثبت‌نام',   'registrations.manage'],
    'reseller_tickets'       => ['/reseller/tickets',       'تیکت‌های مشتریان',      'tickets.manage'],
    'reseller_announcements' => ['/reseller/announcements', 'اطلاعیه‌ها',            'announcements.manage'],
    'reseller_tariffs'       => ['/reseller/tariffs',       'تعرفه‌ها',              'tariffs.manage'],
    'reseller_package_admin' => ['/reseller/packages',      'بسته‌ها و کدهای تخفیف', 'packages.manage'],
    'reseller_finance'       => ['/reseller/finance',       'مالی و سود',            'finance.view'],
    'reseller_payments'      => ['/reseller/payments',      'پرداخت‌های مشتریان',    'payments.manage'],
    'reseller_staff'         => ['/reseller/staff',         'کارمندان',              'owner'],
    'reseller_settings'      => ['/reseller/settings',      'برند، دامنه و ثبت‌نام', 'owner'],
];
if (!$ctx['staff']) {
    $resellerNav['reseller_back'] = ['/dashboard', 'بازگشت به پنل کاربری', null];
}
$resellerNav = array_filter($resellerNav, static fn(array $item): bool => $item[2] === null || reseller_can($ctx, $item[2]));
$resellerGroups = menu_group_items($resellerNav, [
    'r_general'   => ['نمایندگی',      ['reseller_dashboard']],
    'r_customers' => ['مشتریان',       ['reseller_customers', 'reseller_registrations', 'reseller_tickets', 'reseller_announcements']],
    'r_sales'     => ['فروش و قیمت',   ['reseller_tariffs', 'reseller_package_admin', 'reseller_finance', 'reseller_payments']],
    'r_settings'  => ['تنظیمات',       ['reseller_staff', 'reseller_settings', 'reseller_back']],
]);
$badges = [
    'reseller_tickets'       => reseller_ticket_count($ctx['reseller_id'], 'open'),
    'reseller_registrations' => reseller_registration_count($ctx['reseller_id']),
    'reseller_payments'      => manual_payments_pending_count($ctx['reseller_id']),
];
$brand = current_brand();
$resellerOwner = reseller_owner_user($ctx['reseller_id']);
$notificationUnread = notification_unread_count((int)$me['id']);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'پنل نمایندگی') ?> — <?= e($brand['name']) ?></title>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/panel-layout-fixes.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/panel.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/reseller.css')) ?>">
<script src="<?= e(asset_url('/assets/js/panel.js')) ?>" defer></script>
<?= brand_color_style($brand) ?>
</head>
<body class="panel panel-reseller">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<div class="shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar" aria-label="منوی پنل نمایندگی">
    <div class="sidebar-head">
    <a class="brand" href="/reseller">
      <?php if ($brand['logo'] !== ''): ?><img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>"><?php else: ?><strong><?= e($brand['name']) ?></strong><?php endif; ?>
    </a>
    <button type="button" class="sidebar-close icon-button" id="sidebarClose" aria-label="بستن منو"><?= panel_icon('close') ?></button>
    </div>
    <div class="sidebar-workspace">
      پنل نمایندگی
      <span class="reseller-workspace-name"><?= e($resellerOwner['full_name'] ?: $resellerOwner['username']) ?><?= $ctx['staff'] ? ' · کارمند' : '' ?></span>
    </div>
    <nav id="panelNav" aria-label="بخش‌های پنل نمایندگی">
      <?php foreach ($resellerGroups as $groupId => [$groupLabel, $groupItems]): ?>
        <?php $groupActive = array_key_exists($active ?? '', $groupItems); ?>
        <details class="nav-group<?= $groupActive ? ' has-active' : '' ?>" data-group="<?= e($groupId) ?>" open>
          <summary class="nav-label"><span><?= e($groupLabel) ?></span><span class="nav-group-count" aria-hidden="true"><?= to_persian_digits((string)count($groupItems)) ?></span><span class="nav-chevron" aria-hidden="true"></span></summary>
          <div class="nav-group-items">
          <?php foreach ($groupItems as $key => [$href, $label]): ?>
            <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon"><?= panel_icon($key) ?></span><span class="nav-text"><?= e($label) ?></span>
              <?php if (($badges[$key] ?? 0) > 0): ?><span class="badge badge-pending" style="margin-inline-start:auto"><?= to_persian_digits((string)min(99, $badges[$key])) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><?= e($brand['name']) ?> · پنل نمایندگی</div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" type="button" id="menuToggle" aria-label="باز کردن منو" aria-controls="sidebar" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <div class="topbar-heading"><span class="topbar-eyebrow">پنل نمایندگی</span><h1 class="page-title"><?= e($pageTitle ?? '') ?></h1></div>
      <div class="topbar-right">
        <span class="credit-pill" title="اعتبار نماینده">
          اعتبار نمایندگی: <strong class="ltr"><?= to_persian_digits(number_format((int)wallet_balance($ctx['reseller_id'])['available'])) ?></strong> ریال
        </span>
        <a class="icon-button notification-button" href="/notifications.php" title="اعلان‌ها" aria-label="اعلان‌ها<?= $notificationUnread > 0 ? '، ' . to_persian_digits((string)$notificationUnread) . ' خوانده‌نشده' : '' ?>">
          <?= panel_icon('bell') ?><?php if ($notificationUnread > 0): ?> <strong><?= to_persian_digits((string)min(99, $notificationUnread)) ?></strong><?php endif; ?>
        </a>
        <a class="user-chip" href="/profile.php" title="حساب کاربری و رمز عبور">
          <span class="user-avatar" aria-hidden="true"><?= e(mb_substr($me['full_name'] ?: $me['username'], 0, 1)) ?></span><span class="user-name"><?= e($me['full_name'] ?: $me['username']) ?></span>
        </a>
        <form method="post" action="/logout.php" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" class="icon-button logout-button" title="خروج از حساب" aria-label="خروج از حساب"><?= panel_icon('logout') ?></button>
        </form>
      </div>
    </header>

    <main id="main" tabindex="-1" class="content content-<?= e($active ?? 'reseller') ?>">
      <?php $impersonationBanner = is_impersonating() ? impersonation_banner_context() : null; ?>
      <?php if ($impersonationBanner): ?>
        <div class="flash flash-error" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
          <span><strong>حالت پشتیبانی فعال است</strong> — پنل نمایندگی فقط قابل مشاهده است و تغییری ذخیره نمی‌شود.</span>
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
