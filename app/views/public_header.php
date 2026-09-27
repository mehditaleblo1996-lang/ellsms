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
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body class="lp-body">

<header class="lp-nav">
  <div class="lp-nav-inner">
    <a href="/landing.php" class="lp-brand" aria-label="ELLSMS — پنل هوشمند پیامک">
      <svg class="lp-brand-mark" viewBox="0 0 40 34" aria-hidden="true">
        <defs><linearGradient id="lpBrandGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b82f6"/><stop offset="1" stop-color="#6d4aff"/></linearGradient></defs>
        <path d="M10 2h22a6 6 0 0 1 6 6v11a6 6 0 0 1-6 6H19l-7 7v-7h-2a6 6 0 0 1-6-6V8a6 6 0 0 1 6-6Z" fill="url(#lpBrandGrad)"/>
        <circle cx="15" cy="13.5" r="2" fill="#fff"/><circle cx="21" cy="13.5" r="2" fill="#fff"/><circle cx="27" cy="13.5" r="2" fill="#fff"/>
        <path d="M0 9h3M1 14h2.4M0 19h3" stroke="#60a5fa" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <span class="lp-brand-text"><b><i>ELL</i>SMS</b><small>پنل هوشمند پیامک</small></span>
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

<main>
