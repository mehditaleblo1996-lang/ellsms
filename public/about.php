<?php
require_once __DIR__ . '/../app/bootstrap.php';
// «درباره ما» — the company page of the public marketing site (docs/about.md). Fixed HTML/CSS/SVG,
// no table behind it. A reseller's own host has no platform marketing site: it opens on the login,
// exactly like landing.php.
if (reseller_brand_for_host() !== null) redirect('/login');

$pageTitle = 'درباره ما';
$lpNavCurrent = 'about';
$extraStyles = ['/assets/css/about.css'];
$metaDescription = 'داتیس رایان گستر ایرانیان؛ راهکارهای یکپارچه ارتباطی، زیرساختی و هوشمند برای سازمان‌ها، بانک‌ها، نهادهای حاکمیتی و پلتفرم‌های دیجیتال: پیامک، خدمات ابری، هوش مصنوعی، فناوری اطلاعات و ارتباطات سازمانی.';

/**
 * The page's line icons (24×24, stroke = currentColor). Parts with an ab-i-* class are what
 * about.css animates — the typing dots, the cloud's arrows, the server LEDs and so on.
 */
$abIcon = static function (string $name): string {
    $paths = [
        'sms'        => '<path d="M21 11.5a8.5 8.5 0 0 1-12.4 7.6L3.5 20.5l1.4-4.6A8.5 8.5 0 1 1 21 11.5Z"/><circle class="ab-i-dot" cx="8.5" cy="11.5" r=".9"/><circle class="ab-i-dot" cx="12.5" cy="11.5" r=".9"/><circle class="ab-i-dot" cx="16.5" cy="11.5" r=".9"/>',
        'cloud'      => '<path d="M7 18.5a4.5 4.5 0 0 1-.6-9A6 6 0 0 1 18 11a3.75 3.75 0 0 1-.5 7.5Z"/><path class="ab-i-up" d="M10 16.5v-4m-1.6 1.6L10 12.5l1.6 1.6"/><path class="ab-i-down" d="M14 12.5v4m-1.6-1.6L14 16.5l1.6-1.6"/>',
        'ai'         => '<rect x="6.5" y="6.5" width="11" height="11" rx="2.5"/><path class="ab-i-pin" d="M9.5 2.5v4m5-4v4m-5 11v4m5-4v4m-12-12h4m-4 5h4m11-5h4m-4 5h4"/><path class="ab-i-spark" d="m12 8.8.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9Z"/>',
        'it'         => '<rect x="3" y="3.5" width="18" height="7.5" rx="2"/><rect x="3" y="13" width="18" height="7.5" rx="2"/><path d="M11 7.25h6m-6 9.5h6"/><circle class="ab-i-led" cx="7" cy="7.25" r="1"/><circle class="ab-i-led is-b" cx="7" cy="16.75" r="1"/>',
        'phone'      => '<path class="ab-i-ring" d="M5 3.5h3.6l1.9 4.8-2.4 1.5a11 11 0 0 0 5.1 5.1l1.5-2.4 4.8 1.9V18a2 2 0 0 1-2 2A16.5 16.5 0 0 1 3 5.5a2 2 0 0 1 2-2Z"/><path class="ab-i-wave" d="M15 2.8a6.5 6.5 0 0 1 6.2 6.2"/><path class="ab-i-wave is-b" d="M15 6.4a3 3 0 0 1 2.6 2.6"/>',
        'building'   => '<path d="M3 21h18M5 21V8l7-4.5L19 8v13"/><path d="M9 10h1.5m3.5 0H15M9 14h1.5m3.5 0H15M10 21v-3.5h4V21"/>',
        'bank'       => '<path d="M3 9.5 12 4l9 5.5Z"/><path d="M5 9.5v8m4.7-8v8m4.6-8v8M19 9.5v8M3.5 17.5h17M2.5 21h19"/>',
        'ministry'   => '<path d="M4 21h16M6 21v-9h12v9M5 12l7-5 7 5"/><path d="M12 7V2.5l3.5 1.2L12 5"/><path d="M9.5 15v3m2.5-3v3m2.5-3v3"/>',
        'enterprise' => '<path d="M3 21h18M5 21V4.5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1V21m0-12h5a1 1 0 0 1 1 1v11"/><path d="M8 7.5h3M8 11h3M8 14.5h3m6-1.5h.01M17 16.5h.01"/>',
        'superapp'   => '<rect x="6" y="2" width="12" height="20" rx="2.6"/><rect x="8.6" y="5.6" width="2.8" height="2.8" rx=".7"/><rect x="12.6" y="5.6" width="2.8" height="2.8" rx=".7"/><rect x="8.6" y="9.6" width="2.8" height="2.8" rx=".7"/><rect class="ab-i-spark" x="12.6" y="9.6" width="2.8" height="2.8" rx=".7"/><path d="M11 18.5h2"/>',
        'platform'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'orgs'       => '<circle cx="12" cy="5.5" r="2.5"/><circle cx="5" cy="18.5" r="2.5"/><circle cx="19" cy="18.5" r="2.5"/><path d="M12 8v4m0 0-5.2 4.6M12 12l5.2 4.6"/>',
        'continuity' => '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1L20.5 8.5"/><path d="M20.5 3.5v5h-5"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/>',
        'shield'     => '<path d="M12 2.5 4.5 5.5v5.8c0 4.9 3.2 8.4 7.5 10.2 4.3-1.8 7.5-5.3 7.5-10.2V5.5Z"/><rect class="ab-i-lock" x="9" y="10.5" width="6" height="5" rx="1.2"/><path class="ab-i-lock" d="M10.4 10.5V9a1.6 1.6 0 0 1 3.2 0v1.5"/>',
        'gauge'      => '<path d="M3.5 18a8.5 8.5 0 1 1 17 0"/><path class="ab-i-needle" d="m12 18 4-5"/><path d="M12 6.5v1.5M6.8 9.2l1.1 1.1m9.3-1.1-1.1 1.1"/>',
        'smile'      => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.3a4.5 4.5 0 0 0 7 0"/><path class="ab-i-blink" d="M9 9.8h.01M15 9.8h.01"/>',
        'pulse'      => '<path class="ab-i-trace" d="M2.5 12h4l2.2-5.5 4.3 11 2.2-5.5h6.3"/>',
        'bolt'       => '<path class="ab-i-flash" d="M13.5 2.5 4.5 14h7l-1 7.5 9-11.5h-7Z"/>',
        'scale'      => '<path d="M3.5 20.5h17"/><path class="ab-i-trace" d="m4 16 5-5 3.5 3.5L19.5 7"/><path d="M15 7h4.5v4.5"/>',
        'headset'    => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path class="ab-i-blink" d="M19 20c0 1.2-1.4 2-3 2h-2.5"/>',
        'target'     => '<circle cx="11" cy="13" r="8"/><circle class="ab-i-ring2" cx="11" cy="13" r="4.5"/><circle cx="11" cy="13" r="1.2"/><g class="ab-i-arrow"><path d="M11 13 19.5 4.5"/><path d="M16.5 3.5v4h4"/></g>',
        'vision'     => '<path d="M2.5 20.5 9 10.5l4 5.5 2.5-3.5 6 8Z"/><path d="M13 16V4.5"/><path class="ab-i-flag" d="M13 4.5h5.5l-1.5 2 1.5 2H13"/>',
        'trust'      => '<path d="m12 2.3 2.4 2.1 3.2-.3.9 3.1 2.8 1.6-1.1 3 1.1 3-2.8 1.6-.9 3.1-3.2-.3L12 21.7l-2.4-2.1-3.2.3-.9-3.1-2.8-1.6 1.1-3-1.1-3 2.8-1.6.9-3.1 3.2.3Z"/><path class="ab-i-check" d="m8.5 12 2.4 2.4 4.6-4.8"/>',
        'bulb'       => '<path d="M9 18h6m-5 3h4"/><path d="M12 3.5a5.8 5.8 0 0 0-3.4 10.5c.6.5 1 1.2 1 2V16h4.8v-.1c0-.8.4-1.5 1-2A5.8 5.8 0 0 0 12 3.5Z"/><path class="ab-i-rays" d="M12 .8v.9M4.2 4l.7.7M19.8 4l-.7.7M1.5 10.5h1m19 0h1"/>',
        'heart'      => '<circle cx="9" cy="7.5" r="3.5"/><path d="M2.5 20.5a6.5 6.5 0 0 1 13 0"/><path class="ab-i-beat" d="M18.4 9.6c-.7-1.4-3-1.3-3 .6 0 1.6 3 3.6 3 3.6s3-2 3-3.6c0-1.9-2.3-2-3-.6Z"/>',
        'award'      => '<circle cx="12" cy="9" r="6.2"/><path d="m8.4 14-1.6 7.5 5.2-3 5.2 3-1.6-7.5"/><path class="ab-i-star" d="m12 5.8 1 2 2.2.3-1.6 1.6.4 2.2-2-1-2 1 .4-2.2-1.6-1.6 2.2-.3Z"/>',
        'sparkle'    => '<path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5Z"/><path d="m19 15 .9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9Z"/>',
        'quote'      => '<path d="M10 7H6.5A2.5 2.5 0 0 0 4 9.5V13h6V7Zm0 6c0 2.5-1.5 4-4 4.5M20 7h-3.5A2.5 2.5 0 0 0 14 9.5V13h6V7Zm0 6c0 2.5-1.5 4-4 4.5"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
};

// The five service areas: [icon, title, short label for the hub, text, tags, colour].
$abServices = [
    ['sms', 'پیامک و راهکارهای ارتباطی هوشمند', 'پیامک',
        'ارائه زیرساخت‌های ارسال و دریافت پیامک، پیامک‌های تراکنشی، اطلاع‌رسانی سازمانی، احراز هویت و راهکارهای ارتباطی یکپارچه برای کسب‌وکارهای پرتراکنش و پلتفرم‌های دیجیتال.',
        ['ارسال و دریافت پیامک', 'پیامک تراکنشی', 'اطلاع‌رسانی سازمانی', 'احراز هویت'], 'is-blue'],
    ['cloud', 'خدمات ابری و زیرساخت فناوری', 'ابر و زیرساخت',
        'توسعه و ارائه راهکارهای زیرساختی و ابری با هدف پشتیبانی از سامانه‌های سازمانی، افزایش بهره‌وری و فراهم‌سازی بستر توسعه خدمات دیجیتال.',
        ['زیرساخت ابری', 'سامانه‌های سازمانی', 'بستر خدمات دیجیتال'], 'is-cyan'],
    ['ai', 'هوش مصنوعی و اتوماسیون', 'هوش مصنوعی',
        'بهره‌گیری از ظرفیت هوش مصنوعی و فناوری‌های نوین برای هوشمندسازی فرآیندها، خودکارسازی عملیات و توسعه راهکارهای نسل جدید سازمانی.',
        ['هوشمندسازی فرآیندها', 'خودکارسازی عملیات', 'راهکارهای نسل جدید'], 'is-violet'],
    ['it', 'خدمات فناوری اطلاعات', 'فناوری اطلاعات',
        'ارائه خدمات و راهکارهای تخصصی فناوری اطلاعات با تمرکز بر نیازهای عملیاتی، توسعه‌ای و زیرساختی سازمان‌ها و کسب‌وکارهای بزرگ.',
        ['نیازهای عملیاتی', 'توسعه‌ای', 'زیرساختی'], 'is-green'],
    ['phone', 'تلفن و ارتباطات سازمانی', 'ارتباطات تلفنی',
        'طراحی و ارائه راهکارهای ارتباطات تلفنی و سازمانی با هدف یکپارچه‌سازی ارتباطات، بهبود تعاملات و ارتقای کیفیت ارتباط با مشتریان.',
        ['یکپارچه‌سازی ارتباطات', 'بهبود تعاملات', 'ارتباط با مشتریان'], 'is-amber'],
];

// Hub in the hero: the five areas on a circle (r = 150 in a 400×400 box) around the brand mark.
$abHub = [];
foreach ($abServices as $k => $svc) {
    $a = deg2rad(-90 + $k * 72);
    $abHub[] = ['x' => round(200 + 150 * cos($a), 1), 'y' => round(200 + 150 * sin($a), 1), 'svc' => $svc];
}

// «از پیامک تا سبد کامل خدمات»: the four areas that grew out of SMS, as branches of one tree
// (520×300 box; the root sits on the right, the RTL start).
$abBranches = [];
foreach (array_slice($abServices, 1) as $k => $svc) {
    $y = 42 + $k * 72;
    $curve = "C250 150 240 {$y} 150 {$y}";
    $abBranches[] = ['y' => $y, 'svc' => $svc, 'branch' => 'M330 150' . $curve, 'flow' => 'M450 150H330' . $curve];
}

$abAudience = [
    ['orgs', 'سازمان‌ها'], ['ministry', 'نهادهای حاکمیتی'], ['bank', 'بانک‌ها'],
    ['enterprise', 'مجموعه‌های بزرگ اقتصادی'], ['platform', 'پلتفرم‌های دیجیتال'],
];
$abStakes = [
    ['continuity', 'تداوم کسب‌وکار'], ['shield', 'امنیت'], ['gauge', 'بهره‌وری'], ['smile', 'تجربه مشتری'],
];
$abSectors = [
    ['bank', 'بانک‌ها'], ['ministry', 'وزارتخانه‌ها'], ['enterprise', 'سازمان‌های بزرگ'], ['superapp', 'سوپراپلیکیشن‌ها'],
];
$abPillars = [
    ['pulse', 'پایداری'], ['shield', 'امنیت'], ['bolt', 'سرعت پاسخ‌گویی'], ['scale', 'مقیاس‌پذیری'], ['headset', 'کیفیت پشتیبانی'],
];
$abValues = [
    ['trust', 'اعتماد و مسئولیت‌پذیری', 'پایبندی به تعهدات و حفظ اعتماد مشتریان'],
    ['shield', 'امنیت و پایداری', 'توجه به الزامات حیاتی زیرساخت‌های سازمانی'],
    ['bulb', 'نوآوری و توسعه', 'حرکت مستمر همگام با فناوری‌های روز'],
    ['heart', 'مشتری‌مداری', 'شناخت نیازها و ارائه راهکارهای متناسب با هر سازمان'],
    ['award', 'کیفیت و تخصص', 'تکیه بر دانش فنی و استانداردهای حرفه‌ای در ارائه خدمات'],
];
// The in-page section bar; the same five entries are the «درباره ما» submenu of the site nav
// (app/views/public_header.php).
$abSections = [
    ['intro', 'building', 'معرفی'], ['services', 'sparkle', 'حوزه‌های تخصصی'], ['trust', 'trust', 'اعتماد سازمان‌ها'],
    ['mission', 'target', 'مأموریت و چشم‌انداز'], ['values', 'award', 'ارزش‌های بنیادین'],
];

require __DIR__ . '/../app/views/public_header.php';
?>
<div class="ab-page">

  <!-- Hero: who we are, with the five service areas orbiting the brand mark. -->
  <section class="ab-hero" id="intro" data-ab-live>
    <svg class="lp-banner-circuit" viewBox="0 0 1200 520" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <path d="M0 140h220l40 40h180"/><path d="M0 300h160l50-50h240"/><path d="M0 420h300l40-40h120"/>
      <path d="M1200 110H980l-40 40H800"/><path d="M1200 260h-190l-50 50H760"/><path d="M1200 430H930l-40-40H780"/>
      <path class="is-glow" d="M0 140h220l40 40h180"/><path class="is-glow" d="M1200 260h-190l-50 50H760" style="animation-delay:1.4s"/>
      <path class="is-glow" d="M0 420h300l40-40h120" style="animation-delay:2.6s"/><path class="is-glow" d="M1200 110H980l-40 40H800" style="animation-delay:3.6s"/>
    </svg>
    <div class="ab-hero-inner">
      <div class="ab-hero-copy">
        <p class="ab-eyebrow"><span class="ab-eyebrow-dot" aria-hidden="true"></span>درباره ما</p>
        <h1>داتیس رایان گستر ایرانیان<span class="ab-hero-tag"><span class="lp-heading-accent">راهکارهای یکپارچه</span> ارتباطی، زیرساختی و هوشمند</span></h1>
        <p class="ab-hero-lead">شرکت داتیس رایان گستر ایرانیان با رویکردی فناورانه و آینده‌نگر، در مسیر توسعه و ارائه راهکارهای یکپارچه ارتباطی، زیرساختی و هوشمند فعالیت می‌کند.</p>
        <p class="ab-hero-sub">ما با تکیه بر دانش تخصصی، توانمندی‌های فنی و شناخت عمیق از نیازهای سازمانی، مجموعه‌ای از خدمات و راهکارهای فناوری اطلاعات و ارتباطات را برای سازمان‌ها، نهادهای حاکمیتی، بانک‌ها، مجموعه‌های بزرگ اقتصادی و پلتفرم‌های دیجیتال ارائه می‌دهیم.</p>
        <div class="ab-hero-cta">
          <a href="#services" class="btn btn-primary">حوزه‌های تخصصی ما</a>
          <a href="/contact" class="btn btn-ghost">گفت‌وگو با ما</a>
        </div>
      </div>

      <div class="ab-hub" aria-hidden="true">
        <svg class="ab-hub-svg" viewBox="0 0 400 400">
          <defs>
            <radialGradient id="abHubGlow" cx="50%" cy="50%" r="50%">
              <stop offset="0" stop-color="#7c5cff" stop-opacity=".45"/>
              <stop offset=".55" stop-color="#2e63f6" stop-opacity=".12"/>
              <stop offset="1" stop-color="#2e63f6" stop-opacity="0"/>
            </radialGradient>
            <linearGradient id="abHubPulse" x1="0" x2="1">
              <stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#60a5fa"/>
            </linearGradient>
          </defs>
          <circle cx="200" cy="200" r="190" fill="url(#abHubGlow)"/>
          <circle class="ab-hub-orbit" cx="200" cy="200" r="150"/>
          <circle class="ab-hub-orbit is-inner" cx="200" cy="200" r="96"/>
          <?php foreach ($abHub as $k => $n): ?>
            <line class="ab-hub-spoke" x1="200" y1="200" x2="<?= $n['x'] ?>" y2="<?= $n['y'] ?>"/>
            <line class="ab-hub-pulse" x1="200" y1="200" x2="<?= $n['x'] ?>" y2="<?= $n['y'] ?>" pathLength="100" style="animation-delay:<?= $k * 0.6 ?>s"/>
          <?php endforeach; ?>
          <g class="ab-hub-comet"><circle cx="200" cy="50" r="4"/></g>
          <g class="ab-hub-comet is-slow"><circle cx="200" cy="104" r="3"/></g>
        </svg>
        <div class="ab-hub-core">
          <span class="ab-hub-ring"></span><span class="ab-hub-ring" style="animation-delay:1.3s"></span>
          <img src="<?= e(asset_url('/assets/img/brand/mark-light.svg')) ?>" alt="" width="321" height="204">
        </div>
        <?php foreach ($abHub as $k => $n): [$ico, , $short, , , $tone] = $n['svc']; ?>
          <div class="ab-hub-node <?= $tone ?>" style="left:<?= $n['x'] / 4 ?>%;top:<?= $n['y'] / 4 ?>%;--d:<?= $k * 0.45 ?>s">
            <span class="ab-hub-float"><span class="ab-ico"><?= $abIcon($ico) ?></span><b><?= e($short) ?></b></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- In-page section bar (sticky under the site nav, current section highlighted). -->
  <nav class="ab-subnav" aria-label="بخش‌های صفحه‌ی درباره ما" data-ab-subnav>
    <div class="ab-subnav-inner">
      <?php foreach ($abSections as [$id, $ico, $label]): ?>
        <a href="#<?= $id ?>" data-ab-spy="<?= $id ?>"><span class="ab-ico"><?= $abIcon($ico) ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </nav>

  <!-- From SMS to a full portfolio. -->
  <section class="lp-section ab-story" id="story" data-ab-live>
    <div class="ab-story-grid">
      <div class="ab-story-copy lp-reveal">
        <p class="ab-kicker">مسیر ما</p>
        <h2>از پیامک، تا سبد کامل خدمات فناوری</h2>
        <p>فعالیت تخصصی ما در حوزه خدمات پیامکی و زیرساخت‌های ارتباطی، نقطه آغاز توسعه سبد خدماتی گسترده‌تری بوده است؛ سبدی که امروز حوزه‌هایی همچون خدمات ابری و زیرساختی، هوش مصنوعی و اتوماسیون، فناوری اطلاعات و ارتباطات تلفنی و سازمانی را در بر می‌گیرد.</p>
        <h3 class="ab-mini-title">در کنار چه کسانی هستیم</h3>
        <ul class="ab-audience">
          <?php foreach ($abAudience as $k => [$ico, $label]): ?>
            <li class="lp-reveal" style="--i:<?= $k ?>"><span class="ab-ico"><?= $abIcon($ico) ?></span><?= e($label) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="ab-tree lp-reveal" aria-hidden="true">
        <svg class="ab-tree-svg" viewBox="0 0 520 300" preserveAspectRatio="none">
          <path class="ab-tree-trunk" d="M450 150H330" pathLength="100"/>
          <?php foreach ($abBranches as $k => $b): ?>
            <path class="ab-tree-branch" d="<?= $b['branch'] ?>" pathLength="100" style="--k:<?= $k ?>"/>
            <path class="ab-tree-flow" d="<?= $b['flow'] ?>" pathLength="100" style="--k:<?= $k ?>"/>
          <?php endforeach; ?>
        </svg>
        <div class="ab-tree-root is-blue" style="left:<?= round(450 / 5.2, 2) ?>%;top:50%">
          <span class="ab-ico"><?= $abIcon('sms') ?></span>
          <span><b>پیامک و ارتباطات</b><small>نقطه‌ی آغاز</small></span>
        </div>
        <?php foreach ($abBranches as $k => $b): [$ico, $title, , , , $tone] = $b['svc']; ?>
          <div class="ab-tree-leaf <?= $tone ?>" style="left:<?= round(150 / 5.2, 2) ?>%;top:<?= round($b['y'] / 3, 2) ?>%;--k:<?= $k ?>">
            <span class="ab-ico"><?= $abIcon($ico) ?></span><b><?= e($title) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ab-statement lp-reveal">
      <span class="ab-statement-mark" aria-hidden="true"><?= $abIcon('quote') ?></span>
      <div class="ab-statement-copy">
        <p class="ab-statement-lead">برای سازمان‌های بزرگ، فناوری صرفاً یک ابزار نیست.</p>
        <p>ما به خوبی می‌دانیم که برای سازمان‌های بزرگ، فناوری بخش جدایی‌ناپذیر از تداوم کسب‌وکار، امنیت، بهره‌وری و تجربه مشتری است. از این رو، در داتیس رایان گستر ایرانیان تلاش می‌کنیم راهکارهایی قابل اتکا، توسعه‌پذیر و متناسب با نیازهای واقعی مشتریان سازمانی ارائه کنیم.</p>
      </div>
      <ul class="ab-stakes">
        <?php foreach ($abStakes as $k => [$ico, $label]): ?>
          <li style="--k:<?= $k ?>"><span class="ab-ico"><?= $abIcon($ico) ?></span><?= e($label) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <!-- The five service areas. -->
  <section class="lp-section ab-services" id="services" data-ab-live>
    <div class="lp-section-head lp-reveal">
      <p class="ab-kicker">حوزه‌های تخصصی خدمات</p>
      <h2>پنج حوزه، یک شریک فناورانه</h2>
      <p>از زیرساخت پیامک و ارتباطات تا ابر، هوش مصنوعی، فناوری اطلاعات و تلفن سازمانی؛ همه با یک تیم و یک استاندارد.</p>
    </div>
    <div class="ab-svc-grid">
      <?php foreach ($abServices as $k => [$ico, $title, , $text, $tags, $tone]): ?>
        <article class="ab-svc <?= $tone ?><?= $k === 0 ? ' is-flag' : '' ?> lp-reveal" id="service-<?= $k + 1 ?>" style="--i:<?= $k ?>">
          <span class="ab-svc-no" aria-hidden="true"><?= to_persian_digits(sprintf('%02d', $k + 1)) ?></span>
          <span class="ab-svc-ico"><span class="ab-ico"><?= $abIcon($ico) ?></span></span>
          <h3><?= e($title) ?><?php if ($k === 0): ?> <span class="ab-badge">نقطه‌ی آغاز ما</span><?php endif; ?></h3>
          <p><?= e($text) ?></p>
          <ul class="ab-tags">
            <?php foreach ($tags as $tag): ?><li><?= e($tag) ?></li><?php endforeach; ?>
          </ul>
          <?php if ($k === 0): ?>
            <div class="ab-svc-flag-art" aria-hidden="true">
              <span class="ab-bubble" style="--b:0">کد تأیید شما: <b class="ltr">48213</b></span>
              <span class="ab-bubble is-out" style="--b:1">تراکنش با موفقیت انجام شد ✓</span>
              <span class="ab-bubble" style="--b:2">اطلاع‌رسانی سازمانی</span>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Trust of large organizations. -->
  <section class="lp-section ab-trust" id="trust" data-ab-live>
    <div class="ab-trust-grid">
      <div class="ab-trust-copy lp-reveal">
        <p class="ab-kicker">اعتماد سازمان‌های بزرگ</p>
        <h2>اعتماد سازمان‌های بزرگ، سرمایه ارزشمند ما</h2>
        <p>همکاری با بانک‌ها، وزارتخانه‌ها، سازمان‌های بزرگ و سوپراپلیکیشن‌ها، ما را با پیچیدگی‌ها و حساسیت‌های زیرساخت‌های ارتباطی و فناوری در مقیاس کلان آشنا کرده است.</p>
        <blockquote class="ab-quote">
          <span class="ab-ico" aria-hidden="true"><?= $abIcon('quote') ?></span>
          <p>ما خود را صرفاً تأمین‌کننده سرویس نمی‌دانیم؛ بلکه شریک فناورانه‌ای می‌دانیم که در مسیر توسعه و پایداری زیرساخت‌های دیجیتال مشتریان خود همراه آن‌هاست.</p>
        </blockquote>
      </div>
      <div class="ab-sectors lp-reveal" style="--i:1">
        <?php foreach ($abSectors as $k => [$ico, $label]): ?>
          <div class="ab-sector" style="--k:<?= $k ?>"><span class="ab-ico"><?= $abIcon($ico) ?></span><b><?= e($label) ?></b></div>
        <?php endforeach; ?>
        <span class="ab-sectors-core" aria-hidden="true"><span class="ab-hub-ring"></span><span class="ab-ico"><?= $abIcon('trust') ?></span></span>
      </div>
    </div>
    <div class="ab-pillars lp-reveal">
      <p>در چنین سطحی از خدمات، این مؤلفه‌ها تعیین‌کننده هستند:</p>
      <ul>
        <?php foreach ($abPillars as $k => [$ico, $label]): ?>
          <li style="--k:<?= $k ?>"><span class="ab-ico"><?= $abIcon($ico) ?></span><b><?= e($label) ?></b></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <!-- Mission and vision. -->
  <section class="lp-section ab-mv" id="mission" data-ab-live>
    <div class="ab-mv-grid">
      <article class="ab-mv-card lp-reveal">
        <span class="ab-mv-ico is-violet"><span class="ab-ico"><?= $abIcon('target') ?></span></span>
        <p class="ab-kicker">مأموریت ما</p>
        <h2>ارتباطات هوشمندتر، فرآیندهای کارآمدتر</h2>
        <p>مأموریت داتیس رایان گستر ایرانیان، فراهم‌سازی زیرساخت‌ها و راهکارهای فناورانه‌ای است که ارتباطات سازمانی را هوشمندتر، فرآیندها را کارآمدتر و مسیر تحول دیجیتال را هموارتر می‌سازند.</p>
        <ul class="ab-tags">
          <li>ارتباطات سازمانی هوشمندتر</li><li>فرآیندهای کارآمدتر</li><li>تحول دیجیتال هموارتر</li>
        </ul>
      </article>
      <article class="ab-mv-card lp-reveal" style="--i:1">
        <span class="ab-mv-ico is-cyan"><span class="ab-ico"><?= $abIcon('vision') ?></span></span>
        <p class="ab-kicker">چشم‌انداز ما</p>
        <h2>پیشرو و اثرگذار در صنعت فناوری اطلاعات و ارتباطات</h2>
        <p>تبدیل‌شدن به یکی از مجموعه‌های پیشرو و اثرگذار در صنعت فناوری اطلاعات و ارتباطات کشور، از طریق توسعه زیرساخت‌های ارتباطی، خدمات ابری، راهکارهای هوش مصنوعی و فناوری‌های نوین سازمانی.</p>
        <ul class="ab-tags">
          <li>زیرساخت‌های ارتباطی</li><li>خدمات ابری</li><li>هوش مصنوعی</li><li>فناوری‌های نوین سازمانی</li>
        </ul>
      </article>
    </div>
  </section>

  <!-- Core values. -->
  <section class="lp-section ab-values" id="values" data-ab-live>
    <div class="lp-section-head lp-reveal">
      <p class="ab-kicker">ارزش‌های بنیادین ما</p>
      <h2>آنچه در هر همکاری به آن پایبندیم</h2>
    </div>
    <ul class="ab-value-grid">
      <?php foreach ($abValues as $k => [$ico, $title, $text]): ?>
        <li class="ab-value lp-reveal" style="--i:<?= $k ?>">
          <span class="ab-value-ico"><span class="ab-ico"><?= $abIcon($ico) ?></span></span>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="lp-cta ab-cta lp-reveal">
    <h2>شریک فناورانه‌ی سازمان شما</h2>
    <p>برای گفت‌وگو درباره‌ی نیازهای ارتباطی، زیرساختی و هوشمند سازمانتان با ما در تماس باشید.</p>
    <div class="ab-cta-actions">
      <a href="/contact" class="btn btn-primary">تماس با ما</a>
      <a href="/" class="btn ab-btn-glass">آشنایی با پنل پیامک</a>
    </div>
  </section>
</div>
<script>
(function () {
  // Measure the sticky site nav so the section bar sits right under it (same variable landing.php sets).
  var nav = document.querySelector('.lp-nav');
  function setNavHeight() { if (nav) document.documentElement.style.setProperty('--lp-nav-h', nav.offsetHeight + 'px'); }
  setNavHeight();
  window.addEventListener('resize', setNavHeight);
  if (!('IntersectionObserver' in window)) {
    document.querySelectorAll('[data-ab-live]').forEach(function (s) { s.classList.add('is-live'); });
    return;
  }
  // Looping icon animations run only while their section is on screen.
  var live = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { en.target.classList.toggle('is-live', en.isIntersecting); });
  }, { rootMargin: '80px 0px' });
  document.querySelectorAll('[data-ab-live]').forEach(function (s) { live.observe(s); });

  // Section bar: highlight the section in the middle band of the screen.
  var bar = document.querySelector('[data-ab-subnav]');
  if (!bar) return;
  var links = {};
  bar.querySelectorAll('[data-ab-spy]').forEach(function (a) { links[a.getAttribute('data-ab-spy')] = a; });
  var current = null;
  function mark(id) {
    if (id === current || !links[id]) return;
    current = id;
    Object.keys(links).forEach(function (k) {
      var on = k === id;
      links[k].classList.toggle('is-current', on);
      if (on) links[k].setAttribute('aria-current', 'location'); else links[k].removeAttribute('aria-current');
    });
    // Keep the current chip visible when the bar scrolls sideways on a phone.
    var a = links[id], box = bar.querySelector('.ab-subnav-inner');
    if (box.scrollWidth > box.clientWidth) box.scrollTo({ left: a.offsetLeft - (box.clientWidth - a.offsetWidth) / 2, behavior: 'smooth' });
  }
  var spy = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) mark(en.target.id === 'story' ? 'intro' : en.target.id); });
  }, { rootMargin: '-45% 0px -50% 0px' });
  ['intro', 'story', 'services', 'trust', 'mission', 'values'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) spy.observe(el);
  });
})();
</script>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
