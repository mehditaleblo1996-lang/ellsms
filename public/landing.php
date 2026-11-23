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
  // Icons (24×24 line glyphs) shared by the hero core and the showcases.
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
  // The five product lines: [anchor id, short name, icon, title, explanation, three main services] —
  // the hero core's nodes and the explanation card a click on a node opens.
  $dxLines = [
      ['product-sms',   'پیامک و بازاریابی', 'send',   'سامانه پیامکی و بازاریابی', 'ارسال انبوه، شخصی‌سازی‌شده و زمان‌بندی‌شده، منشی هوشمند و گزارش لحظه‌ای تحویل هر پیام — همه در یک پنل.', ['ارسال انبوه و هدفمند', 'قالب پویا و زمان‌بندی', 'منشی هوشمند و وب‌سرویس']],
      ['product-cloud', 'خدمات ابری',        'cloud',  'خدمات ابری و زیرساختی',     'سرور مجازی با منابع اختصاصی، هاست و دامنه و فضای ذخیره‌سازی ابری امن، با پشتیبانی فنی کارشناسان داتیس.', ['سرور مجازی (VPS)', 'هاست و دامنه', 'فضای ذخیره‌سازی ابری']],
      ['product-ai',    'هوش مصنوعی',        'spark',  'هوش مصنوعی و اتوماسیون',    'محتوای تبلیغاتی را در چند ثانیه بسازید و کارهای تکراری کسب‌وکار را به گردش‌کارهای خودکار بسپارید.', ['تولید محتوا با هوش مصنوعی', 'اتوماسیون فرآیندها', 'چت‌بات و دستیار هوشمند']],
      ['product-it',    'فناوری اطلاعات',    'shield', 'خدمات فناوری اطلاعات',      'راه‌اندازی، نگهداری و ایمن‌سازی شبکه، سرورها و نرم‌افزارهای سازمان شما؛ یک تیم فنی کامل بدون هزینه‌ی استخدام.', ['پشتیبانی شبکه', 'نصب و راه‌اندازی سرور', 'مشاوره‌ی امنیت زیرساخت']],
      ['product-voip',  'تلفن سازمانی',      'phone',  'تلفن و ارتباطات سازمانی',   'سانترال تحت شبکه، منشی تلفنی گویا، صف تماس و اتصال شعب؛ تا هیچ تماس مشتری بی‌پاسخ نماند.', ['سانترال تحت شبکه (VoIP)', 'منشی تلفنی و صف تماس', 'اتصال شعب و ضبط مکالمات']],
  ];
  // Scenes are rendered in their own scope so their loop variables cannot leak into this page.
  $renderProdScene = static function (string $file): void { require $file; };
  // A three-slide scene carousel (docs/landing.md#it-and-telephony-carousels): slides
  // landing_product_scenes/<base>-1..3.php, a caption and dots. Each slide's story replays from the
  // start whenever it comes on; it advances when the active dot's progress line ends (7 s), pauses on
  // hover/focus and while off screen, and can be swiped. Script: the carousel block further down.
  $renderProdCarousel = static function (string $base, string $label, array $titles) use ($renderProdScene): void { ?>
      <div class="dx-scene is-short is-car" data-lp-prod-scene data-pc-car role="group" aria-roledescription="carousel" aria-label="<?= e($label) ?>">
        <div class="pc-slides">
          <?php foreach ($titles as $i => $t): ?>
          <div class="pc-slide<?= $i === 0 ? ' is-active' : '' ?>" data-pc-slide role="group" aria-roledescription="slide" aria-label="<?= e(to_persian_digits((string) ($i + 1)) . ' از ' . to_persian_digits((string) count($titles)) . ': ' . $t) ?>"<?= $i === 0 ? '' : ' aria-hidden="true"' ?>>
            <?php $renderProdScene(__DIR__ . '/../app/views/landing_product_scenes/' . $base . '-' . ($i + 1) . '.php'); ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="pc-bar">
          <p class="pc-title" aria-live="polite"><span data-pc-title><?= e($titles[0]) ?></span></p>
          <div class="pc-dots">
            <?php foreach ($titles as $i => $t): ?>
            <button type="button" class="pc-dot<?= $i === 0 ? ' is-active' : '' ?>" data-pc-dot="<?= $i ?>" data-title="<?= e($t) ?>" aria-label="<?= e('اسلاید ' . to_persian_digits((string) ($i + 1)) . ': ' . $t) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><i></i></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
  <?php };
?>
  <!-- Brand introduction (docs/landing.md#sections-top-to-bottom): the page's h1 and where to read more. -->
  <section class="dx-section dx-intro" aria-labelledby="dxIntroTitle">
    <div class="dx-intro-grid">
      <h1 id="dxIntroTitle" class="dx-rise">یک شریک فناوری برای <span class="dx-accent">همه‌ی نیازهای</span> کسب‌وکار شما</h1>
      <div class="dx-intro-copy dx-rise" style="--i:1">
        <p>از پیامک و بازاریابی تا زیرساخت ابری، هوش مصنوعی، پشتیبانی فناوری اطلاعات و تلفن سازمانی — داتیس رایان گستر ایرانیان همه را یکجا، با یک تیم پشتیبانی ارائه می‌دهد.</p>
        <a href="/about" class="dx-link">آشنایی با داتیس رایان <?= $dxIcon('arrow') ?></a>
      </div>
    </div>
  </section>

  <!-- Hero (docs/landing.md#hero): the signature «هسته‌ی داتیس» — a graphite-and-glass core with the
       DATIS mark, a brushed-metal ring and the five product lines orbiting on it. Pure CSS 3D (no WebGL,
       no library): it renders the same with or without a GPU, turns slowly, tilts a little toward the
       pointer (damped), pauses off-screen and stands still for prefers-reduced-motion. Each node is a
       button: a click stops the orbit, highlights the node and opens its explanation card under the
       core (script at the end of this block). -->
  <section class="dx-hero" aria-label="خدمات داتیس">
    <div class="dx-hero-inner">
      <div class="dx-core" data-dx-core>
        <div class="dx-core-stage">
          <div class="dx-core-world" data-dx-world>
            <span class="dx-core-floor" aria-hidden="true"></span>
            <span class="dx-core-light" aria-hidden="true"></span>
            <div class="dx-ring dx-ring-b" aria-hidden="true"><span class="dx-ring-line"></span></div>
            <div class="dx-ring dx-ring-a">
              <span class="dx-ring-band" aria-hidden="true"></span>
              <span class="dx-ring-line" aria-hidden="true"></span>
              <div class="dx-dust" aria-hidden="true">
                <?php foreach ([[8, 252, 0], [31, 236, 1], [57, 270, 2], [83, 248, 0], [104, 228, 1], [129, 262, 2], [151, 244, 0], [176, 274, 1], [198, 232, 2], [221, 258, 0], [247, 240, 1], [268, 268, 2], [293, 230, 0], [316, 254, 1], [339, 246, 2]] as [$da, $dr, $dv]): ?><i class="is-<?= $dv ?>" style="--a:<?= $da ?>deg;--r:<?= $dr ?>px"></i><?php endforeach; ?>
              </div>
              <div class="dx-orbit" role="group" aria-label="خدمات داتیس؛ برای توضیح هر خدمت روی آن بزنید، یا مدار را بکشید و بچرخانید">
                <?php foreach ($dxLines as $k => [$id, $name, $ico]): ?>
                <span class="dx-beam" data-dx-highlight="<?= $k ?>" data-dx-tone="<?= $k ?>" style="--a:<?= $k * 72 ?>deg;--n:<?= $k ?>" aria-hidden="true"><i></i></span>
                <div class="dx-node" data-dx-tone="<?= $k ?>" style="--a:<?= $k * 72 ?>deg;--n:<?= $k ?>">
                  <button type="button" class="dx-node-face" data-dx-node="<?= $k ?>" aria-controls="dxCoreInfo" aria-pressed="false"><span class="dx-node-ball"><?= $dxIcon($ico) ?></span><span class="dx-node-label"><?= e($name) ?></span></button>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="dx-sphere">
              <span class="dx-sphere-halo" aria-hidden="true"></span>
              <span class="dx-sphere-pulse" aria-hidden="true"></span><span class="dx-sphere-pulse is-2" aria-hidden="true"></span><span class="dx-sphere-pulse is-3" aria-hidden="true"></span>
              <span class="dx-sphere-wave" aria-hidden="true"></span>
              <span class="dx-sphere-shell" aria-hidden="true"></span>
              <button type="button" class="dx-sphere-body" data-dx-sphere aria-label="هسته‌ی داتیس: نمایش همه‌ی خدمات و چرخش مدار">
                <span class="dx-sphere-sweep" aria-hidden="true"></span>
                <img class="dx-sphere-mark" src="<?= e(asset_url('/assets/img/brand/mark-light.svg')) ?>" alt="" width="84" height="54">
                <?php foreach ($dxLines as $k => [$id, $name, $ico]): ?><span class="dx-sphere-icon" data-dx-highlight="<?= $k ?>" data-dx-tone="<?= $k ?>" aria-hidden="true"><?= $dxIcon($ico) ?></span><?php endforeach; ?>
                <span class="dx-sphere-spec" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="dx-core-info" id="dxCoreInfo" aria-live="polite">
        <p class="dx-core-hint" data-dx-hint>روی هر خدمت بزنید تا معرفی شود؛ مدار را بکشید تا بچرخد، یا روی هسته بزنید.</p>
        <?php foreach ($dxLines as $k => [$id, $name, $ico, $title, $desc, $items]): ?>
        <div class="dx-core-card" data-dx-card="<?= $k ?>" data-dx-tone="<?= $k ?>" hidden>
          <div class="dx-core-card-head">
            <span class="dx-core-card-ico"><?= $dxIcon($ico) ?></span>
            <div><h2><?= e($title) ?></h2></div>
            <button type="button" class="dx-core-close" data-dx-close aria-label="بستن توضیح">×</button>
          </div>
          <p><?= e($desc) ?></p>
          <ul><?php foreach ($items as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <a href="#<?= e($id) ?>" class="dx-link">جزئیات <?= e($title) ?> <?= $dxIcon('arrow') ?></a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <div class="lp-scroll-progress" aria-hidden="true"><div class="lp-scroll-progress-bar" id="lpScrollBar"></div></div>

  <!-- Showcase 1 — the SMS platform: what it does, its channels, and a mock-up of a real flow
       (one template → a personal message per row, then the delivery report). -->
  <section id="product-sms" class="dx-section dx-show dx-show-sms" aria-labelledby="product-sms-t">
    <div class="dx-show-grid">
      <div class="dx-show-copy lp-reveal">
        <p class="dx-kicker">سامانه پیامکی و بازاریابی</p>
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
        <p class="dx-kicker">خدمات ابری و زیرساختی</p>
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

  <!-- Showcase 3 — AI, on a soft turquoise-tinted light band: «منشی هوشمند» answering a customer
       (styles: landing-ai.css; animates while on screen via [data-dx-live]). -->
  <section id="product-ai" class="dx-section dx-show dx-show-ai" aria-labelledby="product-ai-t">
    <div class="dx-show-grid">
      <div class="dx-show-copy lp-reveal">
        <p class="dx-kicker">هوش مصنوعی و اتوماسیون</p>
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
      <?php $renderProdCarousel('it', 'نمایش خدمات فناوری اطلاعات', ['پشتیبانی و پایش شبکه', 'سرور و مجازی‌سازی', 'امنیت و نگهداری']); ?>
      <p class="dx-kicker">خدمات فناوری اطلاعات</p>
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
      <?php $renderProdCarousel('voip', 'نمایش خدمات تلفن سازمانی', ['سانترال تحت شبکه', 'منشی گویا و صف تماس', 'شعب، دورکاری و ضبط مکالمات']); ?>
      <p class="dx-kicker">تلفن و ارتباطات سازمانی</p>
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
  // Scene carousels (IT / telephony panels): the active dot's progress line runs 7 s (CSS) and its
  // animationend moves to the next slide, so hover/focus (CSS pause) and off-screen (.is-live off)
  // pause it for free. Dots jump, horizontal swipes step; reduced motion = no autoplay.
  document.querySelectorAll('[data-pc-car]').forEach(function (car) {
    var slides = car.querySelectorAll('[data-pc-slide]'), dots = car.querySelectorAll('[data-pc-dot]');
    var title = car.querySelector('[data-pc-title]'), cur = 0;
    function go(k) {
      cur = (k + slides.length) % slides.length;
      slides.forEach(function (s, i) {
        var on = i === cur;
        s.classList.toggle('is-active', on);
        if (on) s.removeAttribute('aria-hidden'); else s.setAttribute('aria-hidden', 'true');
      });
      dots.forEach(function (d, i) {
        var on = i === cur;
        d.classList.toggle('is-active', on);
        if (on) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current');
      });
      title.textContent = dots[cur].getAttribute('data-title');
    }
    dots.forEach(function (d, i) {
      d.addEventListener('click', function () { go(i); });
      d.querySelector('i').addEventListener('animationend', function () { if (i === cur) go(cur + 1); });
    });
    car.addEventListener('mouseenter', function () { car.classList.add('is-hold'); });
    car.addEventListener('mouseleave', function () { car.classList.remove('is-hold'); });
    car.addEventListener('focusin', function () { car.classList.add('is-hold'); });
    car.addEventListener('focusout', function () { car.classList.remove('is-hold'); });
    var sx = null, sy = 0;
    car.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    car.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; sx = null;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) go(cur + (dx > 0 ? 1 : -1));  // RTL: swipe right = next
    });
  });
})();
(function () {
  // Hero core (docs/landing.md#the-signature-core-hero). One rAF loop drives the orbit angle --rot
  // (degrees) on the 3D world: a slow auto-turn, drag/swipe with momentum, slow-down while a node is
  // hovered, and a damped turn that brings a clicked service to the front. It also eases the pointer
  // tilt (--px/--py). It runs only while the core is on screen and the tab is visible.
  var core = document.querySelector('[data-dx-core]');
  if (!core) return;
  var world = core.querySelector('[data-dx-world]');
  var nodes = core.querySelectorAll('[data-dx-node]');
  var sphere = core.querySelector('[data-dx-sphere]');
  var highlights = core.querySelectorAll('[data-dx-highlight]');
  var info = document.getElementById('dxCoreInfo');
  var hint = info && info.querySelector('[data-dx-hint]');
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var BASE = still ? 0 : 360 / 56;         // auto-turn, degrees per second
  var rot = 0, vel = BASE, sel = null, target = null, hover = false, onScreen = true;
  var tx = 0, ty = 0, px = 0, py = 0, raf = 0, last = 0;
  var drag = null, dragged = false;

  requestAnimationFrame(function () { core.classList.add('is-in'); });

  // Node k sits at angle 72·k on the ring; with the ring tilted back, the point nearest the viewer
  // is at +90°. Choose the turn that brings it there by the shortest way.
  function frontAngle(k) {
    var t = 90 - k * 72, d = ((t - rot) % 360 + 540) % 360 - 180;
    return rot + d;
  }
  function show(k) {
    sel = k;
    nodes.forEach(function (n) { n.setAttribute('aria-pressed', String(n.getAttribute('data-dx-node') === k)); });
    highlights.forEach(function (item) {
      item.classList.toggle('is-selected', item.getAttribute('data-dx-highlight') === k);
    });
    if (info) info.querySelectorAll('[data-dx-card]').forEach(function (c) { c.hidden = c.getAttribute('data-dx-card') !== k; });
    if (hint) hint.hidden = k !== null;
    core.classList.toggle('is-held', k !== null);
    if (k === null) { core.removeAttribute('data-sel'); target = null; }
    else { core.setAttribute('data-sel', k); target = frontAngle(+k); if (still) rot = target; }
    kick();
  }
  function burst() {
    core.classList.remove('is-burst'); void core.offsetWidth; core.classList.add('is-burst');
    setTimeout(function () { core.classList.remove('is-burst'); }, 1100);
  }

  nodes.forEach(function (n) {
    n.addEventListener('click', function (e) {
      if (dragged) { e.preventDefault(); return; }
      var k = n.getAttribute('data-dx-node');
      show(n.getAttribute('aria-pressed') === 'true' ? null : k);
    });
    n.addEventListener('pointerenter', function () { hover = true; });
    n.addEventListener('pointerleave', function () { hover = false; });
    n.addEventListener('focus', function () { hover = true; });
    n.addEventListener('blur', function () { hover = false; });
  });
  function closeCard() {
    if (sel === null) return;
    var trigger = nodes[Number(sel)];
    show(null);
    trigger.focus({ preventScroll: true });
  }
  if (info) info.querySelectorAll('[data-dx-close]').forEach(function (b) {
    b.addEventListener('click', closeCard);
  });
  core.closest('.dx-hero').addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCard();
  });
  if (sphere) sphere.addEventListener('click', function () {
    if (dragged) return;
    show(null); burst();
    if (!still) vel = BASE + 260;            // a spin boost that decays back to the slow turn
  });

  // Drag / swipe anywhere on the core to spin it (horizontal only; vertical swipes still scroll).
  core.addEventListener('pointerdown', function (e) {
    if (e.button !== 0) return;
    drag = { x: e.clientX, y: e.clientY, t: performance.now(), v: 0, on: false, id: e.pointerId };
    dragged = false;
  });
  core.addEventListener('pointermove', function (e) {
    if (!drag || e.pointerId !== drag.id) return;
    var dx = e.clientX - drag.x;
    if (!drag.on) {
      if (Math.abs(dx) < 6 || Math.abs(dx) < Math.abs(e.clientY - drag.y)) return;
      drag.on = true; dragged = true; core.classList.add('is-dragging');
      try { core.setPointerCapture(e.pointerId); } catch (err) {}
      if (sel !== null) show(null);
    }
    var now = performance.now(), dt = Math.max(1, now - drag.t) / 1000;
    var d = -dx * 0.42;                         // the front of the ring follows the finger
    rot += d; drag.v = drag.v * 0.6 + (d / dt) * 0.4;
    drag.x = e.clientX; drag.t = now; kick();
  });
  function endDrag() {
    if (!drag) return;
    if (drag.on) { vel = Math.max(-900, Math.min(900, drag.v)); core.classList.remove('is-dragging'); }
    drag = null;
    setTimeout(function () { dragged = false; }, 0);   // let the click that ends a drag be ignored
  }
  core.addEventListener('pointerup', endDrag);
  core.addEventListener('pointercancel', endDrag);

  if (fine && !still) window.addEventListener('pointermove', function (e) {
    var r = core.getBoundingClientRect();
    tx = Math.max(-1, Math.min(1, (e.clientX - (r.left + r.width / 2)) / (window.innerWidth / 2)));
    ty = Math.max(-1, Math.min(1, (e.clientY - (r.top + r.height / 2)) / (window.innerHeight / 2)));
    kick();
  }, { passive: true });

  function frame(now) {
    var dt = last ? Math.min(0.05, (now - last) / 1000) : 0.016; last = now;
    if (!drag || !drag.on) {
      if (target !== null) {
        rot += (target - rot) * (1 - Math.exp(-dt * 5)); vel = 0;
      } else {
        var want = hover ? BASE * 0.2 : BASE;
        vel += (want - vel) * (1 - Math.exp(-dt * 1.6));
        rot += vel * dt;
      }
    }
    if (rot > 7200 || rot < -7200) { var w = rot % 360; if (target !== null) target += w - rot; rot = w; }
    px += (tx - px) * (1 - Math.exp(-dt * 4)); py += (ty - py) * (1 - Math.exp(-dt * 4));
    world.style.setProperty('--rot', rot.toFixed(2));
    world.style.setProperty('--px', px.toFixed(3));
    world.style.setProperty('--py', py.toFixed(3));
    var moving = !still || (target !== null && Math.abs(target - rot) > 0.05) || Math.abs(tx - px) > 0.001 || Math.abs(ty - py) > 0.001;
    raf = (onScreen && !document.hidden && moving) ? requestAnimationFrame(frame) : 0;
    if (!raf) last = 0;
  }
  function kick() { if (!raf && onScreen && !document.hidden) raf = requestAnimationFrame(frame); }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) {
      onScreen = en[0].isIntersecting; core.classList.toggle('is-paused', !onScreen); kick();
    }).observe(core);
  }
  document.addEventListener('visibilitychange', kick);
  world.style.setProperty('--rot', '0');
  kick();
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
        <div class="lp-guide-body">پرداخت از طریق درگاه بانکی فعال (زرین‌پال یا پرداخت الکترونیک سامان) انجام می‌شود و اعتبار پس از تأیید تراکنش در پنل شما فعال است. شرایط انصراف و بازگشت وجه در <a href="/refund">این صفحه</a> آمده است.</div>
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
