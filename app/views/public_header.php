<?php
/** Shared chrome for public marketing pages (landing, contact) — not
 *  the logged-in app shell in app/views/header.php.
 *  Expects: $pageTitle, optional $metaDescription. */
$loggedIn     = (bool) current_user();
$primaryHref  = $loggedIn ? '/index.php' : '/login.php';
$primaryLabel = $loggedIn ? 'بازگشت به داشبورد' : 'ورود به پنل';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'ELLSMS') ?> — ELLSMS</title>
<?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="lp-body">

<header class="lp-nav">
  <div class="lp-nav-inner">
    <a href="/landing.php" class="lp-brand"><img src="/assets/img/logo.png" alt="ELLSMS"></a>
    <nav class="lp-nav-links">
      <a href="/landing.php#features">امکانات</a>
      <a href="/landing.php#pricing">بسته‌های پیامک</a>
      <a href="/landing.php#how">نحوه‌ی کار</a>
      <a href="/guide.php">راهنمای استفاده</a>
      <a href="/contact.php">تماس با ما</a>
    </nav>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary lp-nav-cta"><?= e($primaryLabel) ?></a>
  </div>
</header>

<main>
