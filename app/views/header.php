<?php
/** expects: $pageTitle (string), $active (string nav key), $me (user array) */
$me = $me ?? require_login();
$nav = [
    'dashboard' => ['/index.php',     'Dashboard',      '▦'],
    'send'      => ['/send.php',      'Send SMS',       '➤'],
    'schedules' => ['/schedules.php', 'Scheduled',      '◷'],
    'reports'   => ['/reports.php',   'Sent report',    '≣'],
    'inbox'     => ['/inbox.php',     'Inbox',          '✉'],
    'contacts'  => ['/contacts.php',  'Contacts',       '☰'],
];
$adminNav = [
    'users'     => ['/users.php',     'Users',          '👤'],
    'settings'  => ['/settings.php',  'Settings',       '⚙'],
];
?><!doctype html>
<html lang="en">
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
      <div class="nav-label">Messaging</div>
      <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
        <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
          <span class="nav-icon"><?= $icon ?></span><?= $label ?>
        </a>
      <?php endforeach; ?>
      <?php if ($me['role'] === 'admin'): ?>
        <div class="nav-label">Administration</div>
        <?php foreach ($adminNav as $key => [$href, $label, $icon]): ?>
          <a href="<?= $href ?>" class="nav-item<?= ($active ?? '') === $key ? ' is-active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><?= $label ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot">v<?= ELLSMS_VERSION ?></div>
  </aside>

  <div class="main">
    <header class="topbar">
      <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
      <div class="topbar-right">
        <?php if ($me['role'] !== 'admin'): ?>
          <span class="credit-pill" title="Remaining SMS credit">
            Credit&nbsp;<strong><?= number_format((int)$me['credit']) ?></strong>
          </span>
        <?php endif; ?>
        <a class="user-chip" href="/profile.php" title="Profile & password">
          <?= e($me['full_name'] ?: $me['username']) ?><?= $me['role'] === 'admin' ? ' · admin' : '' ?>
        </a>
        <a class="btn btn-ghost" href="/logout.php">Sign out</a>
      </div>
    </header>

    <main class="content">
      <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
