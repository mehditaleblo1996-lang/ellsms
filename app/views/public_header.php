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
<title><?= e($pageTitle ?? 'داتیس رایان گستر ایرانیان') ?> — داتیس رایان گستر ایرانیان</title>
<?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body class="lp-body">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>

<header class="lp-nav">
  <div class="lp-nav-inner">
    <a href="/landing.php" class="lp-brand" aria-label="داتیس رایان گستر ایرانیان — پنل هوشمند پیامک">
      <img class="lp-brand-mark" src="<?= e(asset_url('/assets/img/brand-mark.png')) ?>" alt="" aria-hidden="true">
      <span class="lp-brand-text"><b><i>Datis</i> Rayan</b><small>داتیس رایان گستر ایرانیان</small></span>
    </a>
    <nav class="lp-nav-links" id="lpNavLinks">
      <a href="/landing.php#features">امکانات</a>
      <a href="/landing.php#pricing">بسته‌های پیامک</a>
      <a href="/landing.php#how">نحوه‌ی کار</a>
      <a href="/landing.php#faq">سوالات</a>
      <a href="/guide.php">راهنمای استفاده</a>
      <a href="/contact.php">تماس با ما</a>
    </nav>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary lp-nav-cta"><?= e($primaryLabel) ?></a>
    <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-expanded="false" aria-controls="lpNavLinks" aria-label="باز کردن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
  </div>
</header>

<main id="main" tabindex="-1">
