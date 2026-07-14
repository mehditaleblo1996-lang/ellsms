<?php
/** expects: $pageTitle (string), $active (string nav key), $me (user array) */
$me = $me ?? require_login();
$nav = [
    'dashboard' => ['/index.php',     'داشبورد',        '▦'],
    'send'      => ['/send.php',      'ارسال پیامک',    '➤'],
    'schedules' => ['/schedules.php', 'زمان‌بندی‌شده',   '◷'],
    'reports'   => ['/reports.php',   'گزارش ارسال',    '≣'],
    'inbox'     => ['/inbox.php',     'صندوق دریافت',   '✉'],
    'contacts'  => ['/contacts.php',  'مخاطبین',        '☰'],
];
$adminNav = [
    'users'     => ['/users.php',     'کاربران',        '👤'],
    'settings'  => ['/settings.php',  'تنظیمات',        '⚙'],
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
  <aside class="sidebar">
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
      <?php if ($me['role'] === 'admin'): ?>
        <div class="nav-label">مدیریت</div>
        <?php foreach ($adminNav as $key => [$href, $label, $icon]): ?>
          <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><?= $label ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot">ELLSMS v<?= ELLSMS_VERSION ?></div>
  </aside>

  <div class="main">
    <header class="topbar">
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
        <a class="btn btn-ghost" href="/logout.php">خروج</a>
      </div>
    </header>

    <main class="content">
      <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
