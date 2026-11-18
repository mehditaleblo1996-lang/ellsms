<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
// A reseller's own host (app/ResellerPanel.php) has no platform marketing site: it opens on the login.
if (reseller_brand_for_host() !== null) redirect('/login');
// The FAQ describes how an account is actually obtained: with registration
// closed an admin creates accounts; otherwise the visitor signs up, verifies the mobile, and an admin
// approves. (The 'auto_after_otp' mode still lands in pending_admin_approval — see
// registration_verify_otp() — so it is described as approval too.)
$registrationOpen = registration_enabled();
$pageTitle = 'پنل هوشمند پیامک';
$extraStyles = ['/assets/css/home.css', '/assets/css/landing-products.css', '/assets/css/landing-ai.css'];
$metaDescription = 'سامانه پیامکی و بازاریابی، خدمات ابری (VPS، هاست، دامنه و فضای ابری)، هوش مصنوعی و اتوماسیون، خدمات فناوری اطلاعات و تلفن سازمانی VoIP — ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی هوشمند با هوش مصنوعی، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
// «مشتریان و همکاران»: one tile per customer (banks, ministries, companies). The logo is public/assets/img/customers/<slug>.(svg|webp|png|jpg);
// until a file is there the tile shows a coloured monogram in the bank's colour instead.
$customers = [
    ['slug' => 'eghtesad-novin', 'name' => 'بانک اقتصاد نوین', 'mono' => 'ا', 'color' => '#5b2a86'],
    ['slug' => 'sarmayeh',       'name' => 'بانک سرمایه',      'mono' => 'س', 'color' => '#1f4e8c'],
    ['slug' => 'karafarin',      'name' => 'بانک کارآفرین',    'mono' => 'ک', 'color' => '#00843d'],
    ['slug' => 'sina',           'name' => 'بانک سینا',        'mono' => 'س', 'color' => '#0067b1'],
    ['slug' => 'melli',          'name' => 'بانک ملی ایران',   'mono' => 'م', 'color' => '#0b3b74'],
    ['slug' => 'parsian',        'name' => 'بانک پارسیان',     'mono' => 'پ', 'color' => '#a6192e'],
    ['slug' => 'gardeshgari',    'name' => 'بانک گردشگری',     'mono' => 'گ', 'color' => '#c8102e'],
    ['slug' => 'education',      'name' => 'وزارت آموزش و پرورش', 'mono' => 'آ', 'color' => '#0f6e5a'],
    ['slug' => 'ict',            'name' => 'وزارت ارتباطات و فناوری اطلاعات', 'mono' => 'ف', 'color' => '#1d5fa8'],
    ['slug' => 'tapsi',          'name' => 'تپسی',             'mono' => 'ت', 'color' => '#f15a24'],
    ['slug' => 'snapp',          'name' => 'اسنپ',             'mono' => 'ا', 'color' => '#00b85c'],
    ['slug' => 'digikala',       'name' => 'دیجی‌کالا',         'mono' => 'د', 'color' => '#ef394e'],
    ['slug' => 'divar',          'name' => 'دیوار',            'mono' => 'د', 'color' => '#a62626'],
    ['slug' => 'jobinja',        'name' => 'جابینجا',          'mono' => 'ج', 'color' => '#1b8f7a'],
];
foreach ($customers as &$c) {
    $c['logo'] = null;
    foreach (['svg', 'webp', 'png', 'jpg'] as $ext) {
        if (is_file(__DIR__ . "/assets/img/customers/{$c['slug']}.{$ext}")) { $c['logo'] = "/assets/img/customers/{$c['slug']}.{$ext}"; break; }
    }
}
unset($c);
require __DIR__ . '/../app/views/public_header.php';
?>
<?php
  // Icons (24×24 line glyphs) shared by the hero core, the bento grid and the showcases.
  $dxIcons = [
      'send'   => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/>',
      'cloud'  => '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 6 16.5"/><path d="M6 19h11.5"/>',
      'spark'  => '<path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>',
      'shield' => '<path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
      'phone'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
      'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'bot'    => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M9 17h6"/>',
      'server' => '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
      'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/>',
      'db'     => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
      'pulse'  => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
      'pen'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
      'flow'   => '<rect x="3" y="3" width="6" height="6" rx="1.5"/><rect x="15" y="15" width="6" height="6" rx="1.5"/><path d="M6 9v3a3 3 0 0 0 3 3h6"/><path d="m13 13 2 2-2 2"/>',
      'chart'  => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
      'net'    => '<rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-2a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v2"/>',
      'wrench' => '<path d="M9.3 6.3a4 4 0 0 1-5 5L3 13l8 8 3-3-8-8 1.3-1.3a4 4 0 0 0 5-5L11 2 9.3 6.3Z"/>',
      'menu'   => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
      'branch' => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>',
      'rec'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5" fill="currentColor"/>',
      'mic'    => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7"/>',
      'chat'   => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.1-4.6A8 8 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5Z"/>',
      'arrow'  => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
  ];
  $dxIcon = static fn (string $k, string $cls = ''): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . $dxIcons[$k] . '</svg>';
  // The five product lines: [anchor id, short name, icon] — the hero core's nodes and the bento grid.
  $dxLines = [
      ['product-sms',   'پیامک و بازاریابی', 'send'],
      ['product-cloud', 'خدمات ابری',        'cloud'],
      ['product-ai',    'هوش مصنوعی',        'spark'],
      ['product-it',    'فناوری اطلاعات',    'shield'],
      ['product-voip',  'تلفن سازمانی',      'phone'],
  ];
  // Scenes are rendered in their own scope so their loop variables cannot leak into this page.
  $renderProdScene = static function (string $file): void { require $file; };
?>
  <!-- Hero (docs/landing.md#hero): only the signature «هسته‌ی داتیس» (the page's h1 is visually hidden) — a
       graphite-and-glass core with the DATIS mark, a brushed-metal ring and the five product lines
       orbiting on it. Pure CSS 3D (no WebGL, no library): it renders the same with or without a GPU,
       turns slowly, tilts a little toward the pointer (damped, inline script at the end of this block),
       pauses off-screen and stands still for prefers-reduced-motion. -->
  <section class="dx-hero" aria-labelledby="dxHeroTitle">
    <div class="dx-hero-inner">
      <h1 id="dxHeroTitle" class="sr-only">داتیس رایان گستر ایرانیان</h1>
      <div class="dx-core" data-dx-core aria-hidden="true">
        <div class="dx-core-stage">
          <div class="dx-core-world" data-dx-world>
            <span class="dx-core-floor"></span>
            <span class="dx-core-light"></span>
            <div class="dx-ring dx-ring-b"><span class="dx-ring-line"></span></div>
            <div class="dx-ring dx-ring-a">
              <span class="dx-ring-band"></span>
              <span class="dx-ring-line"></span>
              <div class="dx-orbit">
                <?php foreach ($dxLines as $k => [$id, $name, $ico]): ?>
                <div class="dx-node" style="--a:<?= $k * 72 ?>deg">
                  <div class="dx-node-face"><span class="dx-node-ball"><?= $dxIcon($ico) ?></span><span class="dx-node-label"><?= e($name) ?></span></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="dx-sphere">
              <span class="dx-sphere-shell"></span>
              <span class="dx-sphere-body"><img src="<?= e(asset_url('/assets/img/brand/mark-light.svg')) ?>" alt="" width="84" height="54"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="lp-scroll-progress" aria-hidden="true"><div class="lp-scroll-progress-bar" id="lpScrollBar"></div></div>

  <!-- Brand introduction: one statement and where to read more. -->
  <section class="dx-section dx-intro" aria-labelledby="dxIntroTitle">
    <div class="dx-intro-grid">
      <h2 id="dxIntroTitle" class="lp-reveal">یک شریک فناوری برای <span class="dx-accent">همه‌ی نیازهای</span> کسب‌وکار شما</h2>
      <div class="dx-intro-copy lp-reveal" style="--i:1">
        <p>از پیامک و بازاریابی تا زیرساخت ابری، هوش مصنوعی، پشتیبانی فناوری اطلاعات و تلفن سازمانی — داتیس رایان گستر ایرانیان همه را یکجا، با یک تیم پشتیبانی ارائه می‌دهد.</p>
        <a href="/about" class="dx-link">آشنایی با داتیس رایان <?= $dxIcon('arrow') ?></a>
      </div>
    </div>
  </section>

  <!-- «محصولات و خدمات» (#products, docs/landing.md#products): an asymmetric bento grid — the SMS
       platform large, cloud and AI medium, IT and telephony compact. Each card links to its
       showcase further down. -->
  <section id="products" class="dx-section dx-services" aria-labelledby="lpProdTitle">
    <div class="dx-head lp-reveal">
      <p class="dx-kicker">محصولات و خدمات</p>
      <h2 id="lpProdTitle">پنج خدمت، یک تیم</h2>
      <p>هر چه کسب‌وکار شما برای ارتباط، زیرساخت و اتوماسیون لازم دارد، از یک شریک فناوری.</p>
    </div>
    <div class="dx-bento">
      <a href="#product-sms" class="dx-tile dx-tile-sms lp-reveal">
        <div class="dx-tile-text">
          <span class="dx-tag">محصول اصلی</span>
          <h3>سامانه پیامکی و بازاریابی</h3>
          <p>پنل پیامک تحت وب برای ارسال انبوه، شخصی‌سازی‌شده و خودکار.</p>
          <ul class="dx-ticks">
            <li>ارسال انبوه و هدفمند</li>
            <li>زمان‌بندی با تقویم شمسی</li>
            <li>پیامک شخصی با قالب پویا</li>
            <li>گزارش لحظه‌ای تحویل</li>
            <li>ارسال خودکار و دوره‌ای</li>
          </ul>
          <span class="dx-more">جزئیات سامانه پیامکی <?= $dxIcon('arrow') ?></span>
        </div>
        <div class="dx-tile-art dx-art-phone" aria-hidden="true">
          <div class="dx-phone">
            <span class="dx-phone-notch"></span>
            <div class="dx-phone-screen">
              <span class="dx-phone-top"><b>کمپین تخفیف پاییز</b><i>۳ گیرنده</i></span>
              <p class="dx-bubble" style="--i:0">سلام <b>سارا</b>، کد تخفیف شما <b>۴۸۲۱</b> است.</p>
              <p class="dx-bubble" style="--i:1">سلام <b>علی</b>، کد تخفیف شما <b>۴۸۲۲</b> است.</p>
              <p class="dx-bubble" style="--i:2">سلام <b>مریم</b>، کد تخفیف شما <b>۴۸۲۳</b> است.</p>
              <span class="dx-phone-status"><i></i>تحویل شد</span>
            </div>
          </div>
        </div>
      </a>
      <a href="#product-cloud" class="dx-tile dx-tile-cloud lp-reveal" style="--i:1">
        <div class="dx-tile-art dx-art-rack" aria-hidden="true">
          <span class="dx-blade"><i></i><i></i><s></s></span>
          <span class="dx-blade"><i></i><i></i><s></s></span>
          <span class="dx-blade"><i></i><i></i><s></s></span>
        </div>
        <div class="dx-tile-text">
          <span class="dx-tag">زیرساخت</span>
          <h3>خدمات ابری و زیرساختی</h3>
          <p>سرور مجازی، هاست و دامنه و فضای ذخیره‌سازی ابری امن.</p>
          <span class="dx-more">بیشتر بدانید <?= $dxIcon('arrow') ?></span>
        </div>
      </a>
      <a href="#product-ai" class="dx-tile dx-tile-ai lp-reveal" style="--i:2">
        <div class="dx-tile-art dx-art-net" aria-hidden="true">
          <svg viewBox="0 0 200 120">
            <g class="dx-net-links"><path d="M100 60 40 26M100 60 38 92M100 60 160 24M100 60 164 94M100 60 100 12M40 26 38 92M160 24 164 94"/></g>
            <circle cx="40" cy="26" r="6"/><circle cx="38" cy="92" r="6"/><circle cx="160" cy="24" r="6"/><circle cx="164" cy="94" r="6"/><circle cx="100" cy="12" r="5"/>
            <circle class="dx-net-core" cx="100" cy="60" r="18"/>
            <path class="dx-net-spark" d="m100 50 2.6 6.4 6.4 2.6-6.4 2.6-2.6 6.4-2.6-6.4-6.4-2.6 6.4-2.6Z"/>
          </svg>
        </div>
        <div class="dx-tile-text">
          <span class="dx-tag">هوش مصنوعی</span>
          <h3>هوش مصنوعی و اتوماسیون</h3>
          <p>تولید محتوا در چند ثانیه و گردش‌کارهای خودکار برای کارهای تکراری.</p>
          <span class="dx-more">بیشتر بدانید <?= $dxIcon('arrow') ?></span>
        </div>
      </a>
      <a href="#product-it" class="dx-tile dx-tile-it lp-reveal" style="--i:3">
        <div class="dx-tile-art dx-art-badge" aria-hidden="true"><span><?= $dxIcon('shield') ?></span></div>
        <div class="dx-tile-text">
          <h3>خدمات فناوری اطلاعات</h3>
          <p>شبکه، سرور و امنیت سازمان؛ یک تیم فنی کامل، بدون هزینه‌ی استخدام.</p>
          <span class="dx-more">بیشتر بدانید <?= $dxIcon('arrow') ?></span>
        </div>
      </a>
      <a href="#product-voip" class="dx-tile dx-tile-voip lp-reveal" style="--i:4">
        <div class="dx-tile-art dx-art-badge is-wave" aria-hidden="true"><span><?= $dxIcon('phone') ?></span><em><i></i><i></i><i></i><i></i><i></i></em></div>
        <div class="dx-tile-text">
          <h3>تلفن و ارتباطات سازمانی</h3>
          <p>سانترال تحت شبکه، منشی تلفنی گویا و اتصال شعب.</p>
          <span class="dx-more">بیشتر بدانید <?= $dxIcon('arrow') ?></span>
        </div>
      </a>
    </div>
  </section>

  <!-- Showcase 1 — the SMS platform: what it does, its channels, and a mock-up of a real flow
       (one template → a personal message per row, then the delivery report). -->
  <section id="product-sms" class="dx-section dx-show dx-show-sms" aria-labelledby="product-sms-t">
    <div class="dx-show-grid">
      <div class="dx-show-copy lp-reveal">
        <p class="dx-kicker">۰۱ · سامانه پیامکی و بازاریابی</p>
        <h2 id="product-sms-t">هر نفر، <span class="dx-accent">پیام خودش</span>؛ و گزارش هر پیام، همان لحظه</h2>
        <p class="dx-lead">پنل پیامک تحت وب برای ارسال انبوه، شخصی‌سازی‌شده و خودکار؛ از یک پیام تبلیغاتی ساده تا کمپین‌های هدفمند با قالب پویا، زمان‌بندی با تقویم شمسی، منشی هوشمند با هوش مصنوعی و گزارش لحظه‌ای تحویل هر پیام.</p>
        <ul class="dx-feats">
          <li><span class="dx-ico"><?= $dxIcon('send') ?></span><div><strong>ارسال انبوه و هدفمند</strong><span>ارسال به فایل مخاطبین، گروه‌ها، ارسال منطقه‌ای و نظیربه‌نظیر با خط اختصاصی.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('spark') ?></span><div><strong>پیامک هوشمند با قالب پویا</strong><span>یک قالب بنویسید و برای هر ردیف فایل، پیامی با نام و مبلغ و کد خودش بفرستید.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('clock') ?></span><div><strong>زمان‌بندی و ارسال دوره‌ای</strong><span>کمپین‌های تولد، یادآوری قسط و پیام‌های تکرارشونده با تقویم شمسی.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('bot') ?></span><div><strong>منشی هوشمند و وب‌سرویس</strong><span>پاسخ خودکار با هوش مصنوعی، API و SDK برای اتصال سایت و نرم‌افزار شما.</span></div></li>
        </ul>
        <div class="dx-channels">
          <p>کانال‌های ارسال از همان پنل</p>
          <ul>
            <li><?= $dxIcon('chat') ?><div><b>پیامک</b><span>فوری یا زمان‌بندی‌شده، با پیگیری تحویل هر پیام</span></div></li>
            <li><?= $dxIcon('mic') ?><div><b>پیام صوتی</b><span>تماس خودکار روی تلفن ثابت و همراه، بدون نیاز به اینترنت</span></div></li>
            <li><?= $dxIcon('send') ?><div><b>پیام در بله</b><span>ارزان‌تر از پیامک؛ برای هر کس که در بله نبود، پیامک جایگزین</span></div></li>
          </ul>
        </div>
        <div class="dx-actions">
          <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
          <a href="/guide.php" class="btn btn-ghost">راهنمای استفاده</a>
        </div>
      </div>
      <div class="dx-show-visual lp-reveal" style="--i:1" data-dx-live aria-hidden="true">
        <div class="dx-ui dx-ui-template">
          <div class="dx-ui-head"><span class="dx-ui-dots"><i></i><i></i><i></i></span><b>پیامک هوشمند</b></div>
          <div class="dx-ui-body">
            <span class="dx-ui-label">قالب پیام</span>
            <p class="dx-ui-template-text">سلام <b>{نام}</b>، سفارش <b>{کد}</b> شما ارسال شد.</p>
            <div class="dx-ui-rows">
              <span><b>سارا</b>۴۸۲۱</span><span><b>علی</b>۴۸۲۲</span><span><b>مریم</b>۴۸۲۳</span>
            </div>
            <div class="dx-ui-out">
              <p style="--i:0">سلام <b>سارا</b>، سفارش <b>۴۸۲۱</b> شما ارسال شد.</p>
              <p style="--i:1">سلام <b>علی</b>، سفارش <b>۴۸۲۲</b> شما ارسال شد.</p>
              <p style="--i:2">سلام <b>مریم</b>، سفارش <b>۴۸۲۳</b> شما ارسال شد.</p>
            </div>
          </div>
        </div>
        <div class="dx-ui dx-ui-report">
          <div class="dx-ui-report-head"><span>نمونه — ارسال امروز</span><b>۱۲٬۴۸۰</b></div>
          <div class="dx-ui-bars"><i style="--h:45%"></i><i style="--h:70%"></i><i style="--h:52%"></i><i style="--h:88%"></i><i style="--h:64%"></i><i style="--h:96%"></i><i style="--h:78%"></i></div>
          <div class="dx-ui-row"><span class="ltr">0912•••4471</span><em class="is-ok">تحویل شد</em></div>
          <div class="dx-ui-row"><span class="ltr">0935•••1187</span><em class="is-ok">تحویل شد</em></div>
          <div class="dx-ui-row"><span class="ltr">0919•••0032</span><em class="is-wait">در حال ارسال</em></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Showcase 2 — cloud: the animated rack scene on a graphite panel, text on the other side. -->
  <section id="product-cloud" class="dx-section dx-show dx-show-cloud" aria-labelledby="product-cloud-t">
    <div class="dx-show-grid is-flip">
      <div class="dx-show-visual lp-reveal" aria-hidden="true">
        <div class="dx-scene" data-lp-prod-scene><?php $renderProdScene(__DIR__ . '/../app/views/landing_product_scenes/cloud.php'); ?></div>
      </div>
      <div class="dx-show-copy lp-reveal" style="--i:1">
        <p class="dx-kicker">۰۲ · خدمات ابری و زیرساختی</p>
        <h2 id="product-cloud-t">زیرساختی پایدار، <span class="dx-accent">هر وقت بزرگ‌تر شدید</span></h2>
        <p class="dx-lead">زیرساخت پایدار و مقیاس‌پذیر برای میزبانی سایت، اپلیکیشن و داده‌های کسب‌وکار شما؛ از یک هاست ساده برای وب‌سایت شرکتی تا سرور مجازی قدرتمند و فضای ذخیره‌سازی ابری امن، با منابع قابل ارتقا و پشتیبانی فنی کارشناسان داتیس رایان.</p>
        <ul class="dx-feats is-grid">
          <li><span class="dx-ico"><?= $dxIcon('server') ?></span><div><strong>سرور مجازی (VPS)</strong><span>منابع اختصاصی پردازنده، حافظه و دیسک SSD، دسترسی کامل root و انتخاب سیستم‌عامل.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('globe') ?></span><div><strong>هاست و دامنه</strong><span>هاست لینوکسی و وردپرسی با کنترل‌پنل، گواهی SSL و ثبت و مدیریت دامنه‌ی ملی و بین‌المللی.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('db') ?></span><div><strong>فضای ذخیره‌سازی ابری</strong><span>نگهداری امن فایل‌ها و نسخه‌های پشتیبان با امکان افزایش ظرفیت هر زمان که لازم شد.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('pulse') ?></span><div><strong>پایش و پشتیبان‌گیری</strong><span>نظارت بر سلامت سرویس‌ها و پشتیبان‌گیری منظم برای آسودگی خاطر شما.</span></div></li>
        </ul>
        <div class="dx-actions">
          <a href="/contact.php" class="btn btn-primary">درخواست مشاوره و قیمت</a>
          <a href="/contact.php" class="btn btn-ghost">تماس با کارشناسان</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Showcase 3 — AI, on the page's one graphite band: «منشی هوشمند» answering a customer
       (styles: landing-ai.css; animates while on screen via [data-dx-live]). -->
  <section id="product-ai" class="dx-section dx-show dx-show-ai lp-dark" aria-labelledby="product-ai-t">
    <div class="dx-show-grid">
      <div class="dx-show-copy lp-reveal">
        <p class="dx-kicker">۰۳ · هوش مصنوعی و اتوماسیون</p>
        <h2 id="product-ai-t">کارهای تکراری را <span class="dx-accent">به هوش مصنوعی</span> بسپارید</h2>
        <p class="dx-lead">محتوای تبلیغاتی و پیام‌های کمپین را در چند ثانیه به فارسی روان بسازید و فرآیندهای کسب‌وکار — از ثبت سفارش تا پیگیری مشتری — را خودکار کنید. منشی هوشمند به پیامک‌هایی که به خط شما می‌رسد، از روی اطلاعات کسب‌وکار و جدول‌های خودتان، شبانه‌روزی جواب می‌دهد.</p>
        <ul class="dx-feats is-grid">
          <li><span class="dx-ico"><?= $dxIcon('pen') ?></span><div><strong>تولید محتوا با هوش مصنوعی</strong><span>متن تبلیغاتی، پیامک کمپین، پست شبکه‌های اجتماعی و توضیح محصول، متناسب با لحن برند شما.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('flow') ?></span><div><strong>اتوماسیون فرآیندهای کسب‌وکار</strong><span>گردش‌کارهای خودکار بین فرم، فروشگاه، CRM و پیامک؛ بدون کار دستی و خطای انسانی.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('bot') ?></span><div><strong>دستیار و چت‌بات هوشمند</strong><span>پاسخ‌گویی شبانه‌روزی به مشتریان از روی اطلاعات و جدول‌های کسب‌وکار شما.</span></div></li>
          <li><span class="dx-ico"><?= $dxIcon('chart') ?></span><div><strong>گزارش و تحلیل خودکار</strong><span>خلاصه‌ی روزانه‌ی فروش، پیام‌ها و عملکرد، بدون ساختن دستی گزارش.</span></div></li>
        </ul>
        <div class="dx-actions">
          <a href="/guide.php#guide-ai-secretary" class="btn btn-primary">آشنایی با منشی هوشمند</a>
          <a href="/contact.php" class="btn btn-ghost">درخواست مشاوره</a>
        </div>
      </div>
      <div class="dx-show-visual lp-reveal" style="--i:1" data-dx-live aria-hidden="true">
        <div class="lp-banner-ai">
          <div class="lp-ai-chat">
            <div class="lp-ai-chat-head">
              <span class="lp-ai-orb"><i></i><i></i><svg viewBox="0 0 24 24"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/></svg></span>
              <div><b>منشی هوشمند</b><span>پاسخ خودکار روی خط شما</span></div>
              <em class="lp-ai-live">آنلاین</em>
            </div>
            <div class="lp-ai-chat-body">
              <p class="lp-ai-msg is-in lp-ai-pop" style="--t:.3s">سلام، کیف چرمی قهوه‌ای موجوده؟ قیمتش چنده؟<small>۲۳:۴۸</small></p>
              <p class="lp-ai-typing" style="--t:1.1s"><i></i><i></i><i></i></p>
              <p class="lp-ai-msg is-out lp-ai-pop" style="--t:2.7s">سلام! بله موجود است. قیمت <b>۱٬۸۵۰٬۰۰۰ تومان</b>. برای سفارش، کد <b>۲۱۴</b> را بفرستید.<small>۲۳:۴۸ · منشی هوشمند</small></p>
            </div>
          </div>
          <div class="lp-ai-chip is-a lp-ai-pop" style="--t:3.4s"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/></svg>از جدول «محصولات»</div>
          <div class="lp-ai-chip is-b lp-ai-pop" style="--t:3.8s"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>شبانه‌روزی</div>
        </div>
      </div>
    </div>
  </section>

  <!-- IT services and enterprise telephony: two companion panels, each with its scene. -->
  <section class="dx-section dx-duo" aria-label="خدمات فناوری اطلاعات و تلفن سازمانی">
    <article id="product-it" class="dx-panel lp-reveal" aria-labelledby="product-it-t">
      <div class="dx-scene is-short" data-lp-prod-scene aria-hidden="true"><?php $renderProdScene(__DIR__ . '/../app/views/landing_product_scenes/it.php'); ?></div>
      <p class="dx-kicker">۰۴ · خدمات فناوری اطلاعات</p>
      <h2 id="product-it-t">یک تیم فنی کامل، بدون هزینه‌ی استخدام</h2>
      <p class="dx-lead">کارشناسان داتیس رایان شبکه، سرورها و نرم‌افزارهای سازمان شما را راه‌اندازی می‌کنند، سالم نگه می‌دارند و در برابر تهدیدها ایمن می‌کنند؛ تا شما فقط روی کسب‌وکارتان تمرکز کنید.</p>
      <ul class="dx-mini">
        <li><?= $dxIcon('net') ?><span><b>پشتیبانی شبکه</b>طراحی، راه‌اندازی و عیب‌یابی شبکه‌ی سیمی و بی‌سیم دفتر و شعب، حضوری و از راه دور.</span></li>
        <li><?= $dxIcon('server') ?><span><b>نصب و راه‌اندازی سرور</b>نصب و پیکربندی سرورهای ویندوز و لینوکس، مجازی‌سازی و سرویس‌های سازمانی.</span></li>
        <li><?= $dxIcon('wrench') ?><span><b>نگهداری و پشتیبانی نرم‌افزار</b>به‌روزرسانی، رفع اشکال و پشتیبانی مستمر نرم‌افزارها و سامانه‌های سازمان.</span></li>
        <li><?= $dxIcon('shield') ?><span><b>مشاوره‌ی امنیت زیرساخت</b>ارزیابی آسیب‌پذیری، سخت‌سازی سرورها، فایروال و سیاست‌های دسترسی امن.</span></li>
      </ul>
      <a href="/contact.php" class="btn btn-primary">درخواست مشاوره و قیمت</a>
    </article>
    <article id="product-voip" class="dx-panel lp-reveal" style="--i:1" aria-labelledby="product-voip-t">
      <div class="dx-scene is-short" data-lp-prod-scene aria-hidden="true"><?php $renderProdScene(__DIR__ . '/../app/views/landing_product_scenes/voip.php'); ?></div>
      <p class="dx-kicker">۰۵ · تلفن و ارتباطات سازمانی</p>
      <h2 id="product-voip-t">هیچ تماس مشتری بی‌پاسخ نماند</h2>
      <p class="dx-lead">سیستم تلفن سازمانی مبتنی بر VoIP؛ تماس‌های داخلی و شعب روی بستر اینترنت، منشی تلفنی گویا و صف تماس. داخلی‌ها روی گوشی رومیزی، موبایل یا کامپیوتر، هر جا که تیم شما باشد.</p>
      <ul class="dx-mini">
        <li><?= $dxIcon('phone') ?><span><b>سانترال تحت شبکه (VoIP)</b>راه‌اندازی مرکز تلفن IP، تعریف داخلی‌ها و اتصال به خطوط شهری و سیپ‌ترانک.</span></li>
        <li><?= $dxIcon('menu') ?><span><b>منشی تلفنی و صف تماس</b>پیام خوشامد، منوی گویا («برای فروش ۱ را بگیرید») و توزیع هوشمند تماس‌ها بین کارشناسان.</span></li>
        <li><?= $dxIcon('branch') ?><span><b>اتصال شعب و دورکاری</b>تماس رایگان بین دفاتر و داخلی روی موبایل برای همکارانی که بیرون از دفترند.</span></li>
        <li><?= $dxIcon('rec') ?><span><b>ضبط و گزارش مکالمات</b>ضبط تماس‌ها، گزارش تماس‌های ازدست‌رفته و عملکرد هر داخلی برای کنترل کیفیت.</span></li>
      </ul>
      <a href="/contact.php" class="btn btn-primary">درخواست مشاوره و قیمت</a>
    </article>
  </section>
<script>
(function () {
  // Showcase visuals and product scenes animate only while on screen; with no IntersectionObserver
  // they simply run.
  var els = document.querySelectorAll('[data-lp-prod-scene], [data-dx-live]');
  if (!('IntersectionObserver' in window)) { els.forEach(function (s) { s.classList.add('is-live'); }); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      // Scenes loop, so they stop off-screen; one-shot mock-ups keep their finished state.
      if (en.target.hasAttribute('data-dx-live')) { if (en.isIntersecting) en.target.classList.add('is-live'); }
      else en.target.classList.toggle('is-live', en.isIntersecting);
    });
  }, { threshold: 0.25 });
  els.forEach(function (s) { io.observe(s); });
})();
(function () {
  // Hero core: slow turn (CSS) + a damped tilt toward the pointer. The rAF loop runs only while the
  // pointer moves and the tilt is still settling; off-screen the turn pauses.
  var core = document.querySelector('[data-dx-core]');
  if (!core) return;
  var world = core.querySelector('[data-dx-world]');
  requestAnimationFrame(function () { core.classList.add('is-in'); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) { core.classList.toggle('is-paused', !en[0].isIntersecting); }).observe(core);
  }
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (still || !fine || !world) return;
  var tx = 0, ty = 0, x = 0, y = 0, raf = 0;
  function step() {
    x += (tx - x) * 0.07; y += (ty - y) * 0.07;
    world.style.setProperty('--px', x.toFixed(3));
    world.style.setProperty('--py', y.toFixed(3));
    raf = (Math.abs(tx - x) > 0.001 || Math.abs(ty - y) > 0.001) ? requestAnimationFrame(step) : 0;
  }
  window.addEventListener('pointermove', function (e) {
    var r = core.getBoundingClientRect();
    tx = Math.max(-1, Math.min(1, (e.clientX - (r.left + r.width / 2)) / (window.innerWidth / 2)));
    ty = Math.max(-1, Math.min(1, (e.clientY - (r.top + r.height / 2)) / (window.innerHeight / 2)));
    if (!raf) raf = requestAnimationFrame(step);
  }, { passive: true });
})();
</script>

  <section id="customers" class="dx-section lp-customers lp-reveal" aria-labelledby="dxCustomersTitle">
    <div class="dx-head">
      <p class="dx-kicker">اعتماد سازمان‌ها</p>
      <h2 id="dxCustomersTitle">مشتریان و همکاران</h2>
    </div>
    <?php
    // One rail of monochrome logos (coloured on hover/focus). It holds the list twice so translating
    // it by -50% loops seamlessly; only the first copy is exposed to screen readers.
    $customerRails = [$customers];
    $renderCustomer = static function (array $c): void { ?>
          <li class="lp-bank">
            <span class="lp-bank-logo">
              <?php if ($c['logo'] !== null): ?>
              <img src="<?= e($c['logo']) ?>" alt="لوگوی <?= e($c['name']) ?>" loading="lazy" decoding="async" width="120" height="120">
              <?php else: ?>
              <span class="lp-bank-mono" style="--bank:<?= e($c['color']) ?>" aria-hidden="true"><?= e($c['mono']) ?></span>
              <?php endif; ?>
            </span>
            <span class="lp-bank-name"><?= e($c['name']) ?></span>
          </li>
    <?php };
    ?>
    <!-- Monochrome logos: every pixel becomes charcoal (#25292d), its darkness becomes opacity (1.3 × (1 − luminance)),
         so a logo's white square vanishes and the mark stays. Used by .lp-bank-logo img in style.css. -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
      <filter id="lp-logo-mono" x="0" y="0" width="1" height="1" color-interpolation-filters="sRGB">
        <feColorMatrix type="matrix" values="0 0 0 0 .145  0 0 0 0 .161  0 0 0 0 .176  -0.2764 -0.9298 -0.0939 0 1.3"/>
        <feComposite in2="SourceAlpha" operator="in"/>
      </filter>
    </svg>
    <div class="lp-marquee" data-lp-marquee>
      <?php foreach ($customerRails as $r => $rail): ?>
      <div class="lp-marquee-row"<?= $r === 1 ? ' aria-hidden="true"' : '' ?>>
        <div class="lp-marquee-track">
          <?php foreach ([0, 1] as $copy): ?>
          <ul class="lp-marquee-set"<?= $copy === 1 ? ' aria-hidden="true"' : '' ?>>
            <?php foreach ($rail as $c) $renderCustomer($c); ?>
          </ul>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <script src="/assets/js/landing-customers.js" defer></script>

  <!-- Why DATIS: what the platform actually guarantees (no invented figures). -->
  <section id="trust" class="dx-section dx-why" aria-labelledby="dxWhyTitle">
    <div class="dx-why-head lp-reveal">
      <p class="dx-kicker">چرا داتیس</p>
      <h2 id="dxWhyTitle">ساخته‌شده برای اطمینان</h2>
      <p>پنلی که برای کار روزمره‌ی کسب‌وکارهای ایرانی ساخته شده؛ فارسی از پایه، امن در پرداخت و پیوسته در اجرا.</p>
    </div>
    <div class="dx-why-grid">
      <div class="lp-trust-item lp-reveal" style="--i:0">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        </span>
        <h3>راست‌به‌چپ و تقویم شمسی</h3>
        <p>تمام پنل فارسی و راست‌به‌چپ است؛ تاریخ‌ها با تقویم جلالی و اعداد فارسی نمایش داده می‌شوند.</p>
      </div>
      <div class="lp-trust-item lp-reveal" style="--i:1">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="10" width="18" height="10" rx="2"/><path d="M7 10V7a5 5 0 0 1 10 0v3"/></svg>
        </span>
        <h3>پرداخت امن با زرین‌پال</h3>
        <p>خرید اعتبار مستقیماً از طریق API رسمی زرین‌پال انجام می‌شود؛ هر پرداخت فقط یک‌بار اعتبار می‌دهد.</p>
      </div>
      <div class="lp-trust-item lp-reveal" style="--i:2">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
        </span>
        <h3>پردازش پیوسته در پس‌زمینه</h3>
        <p>زمان‌بندی‌ها، منشی پیامک و ارسال‌های انبوه توسط یک پردازشگر پیوسته دنبال می‌شوند، نه فقط هنگام باز بودن مرورگر.</p>
      </div>
      <div class="lp-trust-item lp-reveal" style="--i:3">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h3>مدیریت متمرکز کاربران</h3>
        <p>مدیر می‌تواند دسترسی، اعتبار و شماره‌های اختصاصی هر کاربر را از یک‌جا کنترل کند.</p>
      </div>
    </div>
  </section>

  <section id="faq" class="dx-section dx-faq" aria-labelledby="dxFaqTitle">
    <div class="dx-faq-head lp-reveal">
      <p class="dx-kicker">پرسش‌ها</p>
      <h2 id="dxFaqTitle">سوالات پرتکرار</h2>
      <p>پاسخ کامل هر بخش در <a href="/guide.php">راهنمای استفاده</a> آمده است.</p>
    </div>
    <div class="lp-guide-list lp-reveal" style="--i:1">
      <details class="lp-guide-item">
        <summary>چطور به پنل دسترسی پیدا می‌کنم؟</summary>
        <?php if (!$registrationOpen): ?>
        <div class="lp-guide-body">ثبت‌نام عمومی در حال حاضر بسته است و حساب کاربری را مدیر سامانه می‌سازد. برای دریافت حساب با ما <a href="/contact">تماس بگیرید</a>.</div>
        <?php else: ?>
        <div class="lp-guide-body">از صفحه‌ی <a href="/register">ثبت‌نام</a> مشخصات خود را وارد کنید و شماره موبایل را با کد پیامکی تأیید کنید. درخواست شما سپس توسط مدیر بررسی می‌شود و پس از تأیید، حساب فعال می‌شود و می‌توانید وارد شوید.</div>
        <?php endif; ?>
      </details>
      <details class="lp-guide-item">
        <summary>خرید اعتبار چقدر طول می‌کشد؟</summary>
        <div class="lp-guide-body">پرداخت از طریق درگاه زرین‌پال انجام می‌شود و اعتبار پس از تأیید تراکنش در پنل شما فعال است. شرایط انصراف و بازگشت وجه در <a href="/refund">این صفحه</a> آمده است.</div>
      </details>
      <details class="lp-guide-item">
        <summary>آیا می‌توانم زمان‌بندی ارسال را لغو یا تغییر دهم؟</summary>
        <div class="lp-guide-body">بله، تا پیش از رسیدن زمان ارسال می‌توانید هر ارسال زمان‌بندی‌شده را از صف حذف یا ویرایش کنید.</div>
      </details>
      <details class="lp-guide-item">
        <summary>منشی پیامک روی چه اساسی پاسخ می‌دهد؟</summary>
        <div class="lp-guide-body">بر اساس قوانینی که خودتان از پیش تعریف می‌کنید — مثلاً کلمات کلیدی خاص در پیامک دریافتی — بدون نیاز به دخالت دستی.</div>
      </details>
      <details class="lp-guide-item">
        <summary>منشی هوشمند چیست و چه هزینه‌ای دارد؟</summary>
        <div class="lp-guide-body">یک منشی هوش مصنوعی روی خط شماست که از اطلاعات کسب‌وکار و جدول‌هایی که خودتان می‌دهید به پیامک‌های ورودی جواب می‌دهد و در صورت نیاز گفتگو را به اپراتور می‌سپارد. هر پاسخ دو بخش هزینه دارد: پیامکِ پاسخ (مثل پیامک عادی) و هزینه‌ی هوش مصنوعی طبق تعرفه، که فقط پس از ارسال موفق پاسخ کسر می‌شود. برای فعال‌سازی با <a href="/contact">پشتیبانی</a> تماس بگیرید. <a href="/guide.php#guide-ai-secretary">راهنمای منشی هوشمند</a></div>
      </details>
      <details class="lp-guide-item">
        <summary>راهنمای کامل استفاده از پنل کجاست؟</summary>
        <div class="lp-guide-body">در صفحه‌ی <a href="/guide.php">راهنمای استفاده</a> مرحله‌به‌مرحله همه‌ی بخش‌های پنل توضیح داده شده است.</div>
      </details>
    </div>
  </section>

  <section class="dx-section dx-cta-wrap">
    <div class="lp-cta dx-cta lp-reveal">
      <span class="dx-cta-ring" aria-hidden="true"></span>
      <p class="dx-kicker">گام بعدی</p>
      <h2>برای کسب‌وکار خود، فناوری مناسبش را انتخاب کنید</h2>
      <p>با کارشناسان داتیس گفت‌وگو کنید تا سرویس مناسب شما را پیشنهاد دهند، یا مستقیم وارد پنل پیامک شوید و اولین ارسال خود را انجام دهید.</p>
      <div class="dx-actions">
        <a href="/contact.php" class="btn btn-primary dx-btn-lg">مشاوره با کارشناسان</a>
        <a href="<?= e($primaryHref) ?>" class="btn btn-ghost dx-btn-lg"><?= e($primaryLabel) ?></a>
      </div>
    </div>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
