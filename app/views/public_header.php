<?php
/** Shared chrome for public marketing pages (landing, contact) — not
 *  the logged-in app shell in app/views/header.php.
 *  Expects: $pageTitle, optional $metaDescription, optional $extraStyles (list of
 *  /assets/... stylesheet paths a single page adds after style.css), optional $lpNavCurrent
 *  ('about' | 'contact': the nav link marked aria-current="page"). */
$loggedIn     = (bool) current_user();
$primaryHref  = $loggedIn ? '/index.php' : '/login.php';
$primaryLabel = $loggedIn ? 'بازگشت به داشبورد' : 'ورود به پنل پیامک';
$navCurrent   = $lpNavCurrent ?? '';
// The «درباره ما» submenu: the sections of public/about.php (keep in step with its $abSections).
$aboutMenu = [
    ['/about#intro', 'معرفی داتیس رایان', 'شرکت، رویکرد و مخاطبان ما',
        '<path d="M3 21h18M5 21V8l7-4.5L19 8v13"/><path d="M9 10h1.5m3.5 0H15M9 14h1.5m3.5 0H15M10 21v-3.5h4V21"/>'],
    ['/about#services', 'حوزه‌های تخصصی', 'پیامک، ابر، هوش مصنوعی، فناوری اطلاعات، تلفن',
        '<path class="lp-mi-spark" d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5Z"/><path d="m19 15 .9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9Z"/>'],
    ['/about#trust', 'اعتماد سازمان‌ها', 'بانک‌ها، وزارتخانه‌ها و سوپراپلیکیشن‌ها',
        '<path d="m12 2.3 2.4 2.1 3.2-.3.9 3.1 2.8 1.6-1.1 3 1.1 3-2.8 1.6-.9 3.1-3.2-.3L12 21.7l-2.4-2.1-3.2.3-.9-3.1-2.8-1.6 1.1-3-1.1-3 2.8-1.6.9-3.1 3.2.3Z"/><path class="lp-mi-check" d="m8.5 12 2.4 2.4 4.6-4.8"/>'],
    ['/about#mission', 'مأموریت و چشم‌انداز', 'مسیری که برای آن کار می‌کنیم',
        '<circle cx="11" cy="13" r="8"/><circle cx="11" cy="13" r="4.5"/><circle cx="11" cy="13" r="1.2"/><g class="lp-mi-arrow"><path d="M11 13 19.5 4.5"/><path d="M16.5 3.5v4h4"/></g>'],
    ['/about#values', 'ارزش‌های بنیادین', 'اعتماد، امنیت، نوآوری، مشتری‌مداری، کیفیت',
        '<circle cx="12" cy="9" r="6.2"/><path d="m8.4 14-1.6 7.5 5.2-3 5.2 3-1.6-7.5"/><path class="lp-mi-star" d="m12 5.8 1 2 2.2.3-1.6 1.6.4 2.2-2-1-2 1 .4-2.2-1.6-1.6 2.2-.3Z"/>'],
];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'داتیس رایان گستر ایرانیان') ?> — داتیس رایان گستر ایرانیان</title>
<?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<?php // The public pages' type is Estedad (fonts.css): fetch its two main weights with the CSS, not after it. ?>
<link rel="preload" href="/assets/fonts/estedad/estedad-arabic-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/estedad/estedad-arabic-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
<?php foreach (($extraStyles ?? []) as $extraStyle): ?><link rel="stylesheet" href="<?= e(asset_url($extraStyle)) ?>">
<?php endforeach; ?>
</head>
<body class="lp-body">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>

<header class="lp-nav">
  <div class="lp-nav-inner">
    <a href="/landing.php" class="lp-brand" aria-label="داتیس رایان گستر ایرانیان — پنل هوشمند پیامک">
      <img class="lp-brand-logo" src="<?= e(asset_url('/assets/img/brand/logo.svg')) ?>" alt="" aria-hidden="true" width="1240" height="478" fetchpriority="high">
    </a>
    <nav class="lp-nav-links" id="lpNavLinks">
      <a href="/landing.php#products">محصولات</a>
      <a href="/landing.php#faq">سوالات</a>
      <a href="/guide.php">راهنمای استفاده</a>
      <?php // «درباره ما» opens a submenu (hover, or the chevron button) with the parts of /about; ?>
      <div class="lp-nav-drop" data-lp-drop>
        <a href="/about"<?= $navCurrent === 'about' ? ' aria-current="page"' : '' ?>>درباره ما</a>
        <button type="button" class="lp-nav-drop-btn" aria-expanded="false" aria-controls="lpNavAbout" aria-label="زیرمنوی درباره ما">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="lp-nav-menu" id="lpNavAbout">
          <?php foreach ($aboutMenu as $k => [$href, $title, $sub, $icon]): ?>
            <a href="<?= e($href) ?>" style="--i:<?= $k ?>">
              <span class="lp-nav-menu-ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><?= $icon ?></svg></span>
              <span class="lp-nav-menu-text"><b><?= e($title) ?></b><small><?= e($sub) ?></small></span>
            </a>
          <?php endforeach; ?>
          <div class="lp-nav-menu-foot">
            <span>شریک فناورانه‌ی سازمان‌ها</span>
            <a href="/contact">گفت‌وگو با ما ←</a>
          </div>
        </div>
      </div>
      <a href="/contact.php"<?= $navCurrent === 'contact' ? ' aria-current="page"' : '' ?>>تماس با ما</a>
    </nav>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary lp-nav-cta"><?= e($primaryLabel) ?></a>
    <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-expanded="false" aria-controls="lpNavLinks" aria-label="باز کردن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
  </div>
</header>

<main id="main" tabindex="-1">
