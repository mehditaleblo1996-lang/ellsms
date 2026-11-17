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
$extraStyles = ['/assets/css/landing-ai.css', '/assets/css/landing-products.css'];
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
  <!-- Top banner: seven animated slides (plain HTML/CSS + a tiny script below, no video file).
       Slide 1 is the five-product «یک مجموعه، پنج خدمت» scene; slide 2 reuses the tilted-phone shot of the ELLSMS promo video as one still (≈43 KB WebP);
       everything else is drawn in CSS/SVG. Auto-advances, pauses on hover/off-screen, and stays on
       the first slide without motion for prefers-reduced-motion. -->
  <section class="lp-banner" data-lp-banner aria-roledescription="carousel" aria-label="معرفی داتیس رایان گستر ایرانیان">
    <svg class="lp-banner-circuit" viewBox="0 0 1200 520" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <path d="M0 140h220l40 40h180"/><path d="M0 300h160l50-50h240"/><path d="M0 420h300l40-40h120"/>
      <path d="M1200 110H980l-40 40H800"/><path d="M1200 260h-190l-50 50H760"/><path d="M1200 430H930l-40-40H780"/>
      <path class="is-glow" d="M0 140h220l40 40h180"/><path class="is-glow" d="M1200 260h-190l-50 50H760" style="animation-delay:1.4s"/>
      <path class="is-glow" d="M0 420h300l40-40h120" style="animation-delay:2.6s"/><path class="is-glow" d="M1200 110H980l-40 40H800" style="animation-delay:3.6s"/>
    </svg>
    <div class="lp-banner-track">

      <!-- Slide 1 «یک مجموعه، پنج خدمت»: the five product lines on a tilted 3D orbit around the DATIS core.
           Opening (~2.1 s): light streaks warp into the centre, a flash, the core ignites, shockwaves, the
           orbit draws, the products swirl out of the core. Then every 2.6 s the orbit turns the next
           product to the front: it bursts (ring + particles), gets a spinning ring, a holographic card
           with its three main services materialises under it, and the copy panel switches to it.
           The chips are buttons that jump to a product. Script: the second <script> after the banner;
           styles: landing-products.css («banner slide 1»); docs/landing.md. Stays on screen 15.3 s. -->
      <?php $suite = [
          ['sms',   'پیامک و بازاریابی', 'سامانه پیامکی و بازاریابی', 'ارسال انبوه، شخصی‌سازی‌شده و زمان‌بندی‌شده، منشی هوشمند و گزارش لحظه‌ای تحویل هر پیام — همه در یک پنل.', ['ارسال انبوه و هدفمند', 'قالب پویا و زمان‌بندی', 'منشی هوشمند و وب‌سرویس'], '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/>'],
          ['cloud', 'خدمات ابری',        'خدمات ابری و زیرساختی',     'سرور مجازی با منابع اختصاصی، هاست و دامنه و فضای ذخیره‌سازی ابری امن، با پشتیبانی فنی کارشناسان داتیس.', ['سرور مجازی (VPS)', 'هاست و دامنه', 'فضای ذخیره‌سازی ابری'], '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 6 16.5"/><path d="M6 19h11.5"/>'],
          ['ai',    'هوش مصنوعی',        'هوش مصنوعی و اتوماسیون',    'محتوای تبلیغاتی را در چند ثانیه بسازید و کارهای تکراری کسب‌وکار را به گردش‌کارهای خودکار بسپارید.', ['تولید محتوا با هوش مصنوعی', 'اتوماسیون فرآیندها', 'چت‌بات و دستیار هوشمند'], '<path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>'],
          ['it',    'فناوری اطلاعات',    'خدمات فناوری اطلاعات',      'راه‌اندازی، نگهداری و ایمن‌سازی شبکه، سرورها و نرم‌افزارهای سازمان شما؛ یک تیم فنی کامل بدون هزینه‌ی استخدام.', ['پشتیبانی شبکه', 'نصب و راه‌اندازی سرور', 'مشاوره‌ی امنیت زیرساخت'], '<path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/>'],
          ['voip',  'تلفن سازمانی',      'تلفن و ارتباطات سازمانی',   'سانترال تحت شبکه، منشی تلفنی گویا، صف تماس و اتصال شعب؛ تا هیچ تماس مشتری بی‌پاسخ نماند.', ['سانترال تحت شبکه (VoIP)', 'منشی تلفنی و صف تماس', 'اتصال شعب و ضبط مکالمات'], '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>'],
      ];
      $suiteNum = static fn (int $k): string => ['۰۱', '۰۲', '۰۳', '۰۴', '۰۵'][$k] . ' / ۰۵'; ?>
      <article class="lp-banner-slide is-active" id="lpSlide1" data-duration="15300" data-lp-suite role="tabpanel" aria-roledescription="slide" aria-label="۱ از ۷">
        <div class="lp-banner-visual lp-suite" aria-hidden="true">
          <div class="lp-suite-stage" data-suite-stage>
            <div class="lp-suite-nebula"></div>
            <div class="lp-suite-dustlayer">
              <?php foreach ([[10,14,0],[86,8,1.2],[94,48,2.1],[74,30,.6],[6,62,1.7],[28,6,2.6],[62,4,.9],[18,40,3],[90,76,1.4],[4,86,.3],[50,2,2.2],[96,24,.8]] as [$x, $y, $d]): ?>
              <i style="--x:<?= $x ?>%;--y:<?= $y ?>%;--d:<?= $d ?>s"></i>
              <?php endforeach; ?>
            </div>
            <svg class="lp-suite-back" viewBox="0 0 100 100" preserveAspectRatio="none">
              <defs>
                <linearGradient id="lpSuiteOrbitG" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#a78bfa"/><stop offset=".5" stop-color="#67e8f9"/><stop offset="1" stop-color="#f0abfc"/></linearGradient>
              </defs>
              <g class="lp-suite-grid">
                <?php foreach ([12, 24, 48] as $rx): ?><ellipse cx="50" cy="38" rx="<?= $rx ?>" ry="<?= round($rx * .472, 2) ?>"/><?php endforeach; ?>
                <?php for ($i = 0; $i < 12; $i++): $t = deg2rad($i * 30); ?><line x1="<?= round(50 + 11 * cos($t), 2) ?>" y1="<?= round(38 + 5.2 * sin($t), 2) ?>" x2="<?= round(50 + 48 * cos($t), 2) ?>" y2="<?= round(38 + 22.6 * sin($t), 2) ?>"/><?php endfor; ?>
              </g>
              <?php for ($i = 0; $i < 3; $i++): ?><ellipse cx="50" cy="38" rx="40" ry="18.9" class="lp-suite-ripple" style="--r:<?= $i ?>"/><?php endfor; ?>
              <ellipse cx="50" cy="38" rx="47" ry="22" class="is-far" pathLength="100"/>
              <ellipse cx="50" cy="38" rx="36" ry="17" class="is-orbit-glow"/>
              <ellipse cx="50" cy="38" rx="36" ry="17" class="is-orbit" pathLength="100"/>
              <ellipse cx="50" cy="38" rx="22" ry="10.5" class="is-inner" pathLength="100"/>
              <ellipse cx="50" cy="38" rx="36" ry="17" class="is-comet" pathLength="100"/>
              <?php foreach ($suite as $k => $p): ?>
              <line x1="50" y1="38" x2="50" y2="38" class="lp-suite-beam is-<?= $p[0] ?>" data-suite-beam/>
              <?php endforeach; ?>
              <circle r="1" class="lp-suite-spark" data-suite-spark/>
              <circle r="1" class="lp-suite-spark" data-suite-spark/>
            </svg>
            <div class="lp-suite-warp">
              <?php for ($i = 0; $i < 18; $i++): ?><i style="--a:<?= $i * 20 + ($i % 3) * 7 ?>deg;--w:<?= ($i * 7) % 5 * .06 ?>s"></i><?php endfor; ?>
            </div>
            <span class="lp-suite-aura"></span>
            <span class="lp-suite-gyro"></span><span class="lp-suite-gyro is-b"></span>
            <span class="lp-suite-shock"></span><span class="lp-suite-shock is-late"></span>
            <div class="lp-suite-core">
              <img src="<?= e(asset_url('/assets/img/brand/mark-light.svg')) ?>" alt="" width="64" height="41">
            </div>
            <svg class="lp-suite-front" viewBox="0 0 100 100" preserveAspectRatio="none">
              <path d="M14 38A36 17 0 0 0 86 38" class="is-glow"/>
              <path d="M14 38A36 17 0 0 0 86 38" class="is-line"/>
            </svg>
            <?php foreach ($suite as $k => $p): ?>
            <div class="lp-suite-pw is-<?= $p[0] ?>" style="--k:<?= $k ?>" data-suite-planet>
              <span class="lp-suite-burst"><?php for ($i = 0; $i < 10; $i++): ?><i style="--a:<?= $i * 36 ?>deg"></i><?php endfor; ?></span>
              <span class="lp-suite-sat"></span>
              <div class="lp-suite-planet"><svg viewBox="0 0 24 24"><?= $p[5] ?></svg></div>
              <span class="lp-suite-label"><?= e($p[1]) ?></span>
            </div>
            <?php endforeach; ?>
            <?php foreach ($suite as $k => $p): ?>
            <div class="lp-suite-card is-<?= $p[0] ?><?= $k === 0 ? ' is-on' : '' ?>" data-suite-card>
              <div class="lp-suite-card-head">
                <span class="lp-suite-card-ico"><svg viewBox="0 0 24 24"><?= $p[5] ?></svg></span>
                <b><?= e($p[2]) ?></b>
              </div>
              <ul>
                <?php foreach ($p[4] as $j => $f): ?><li style="--j:<?= $j ?>"><?= e($f) ?></li><?php endforeach; ?>
              </ul>
            </div>
            <?php endforeach; ?>
            <span class="lp-suite-flash"></span>
          </div>
        </div>
        <div class="lp-banner-copy">
          <h2>یک مجموعه، <span class="is-blue">پنج خدمت</span>؛ همه‌ی فناوری <span class="is-green">کسب‌وکار شما</span></h2>
          <div class="lp-suite-focus">
            <?php foreach ($suite as $k => $p): ?>
            <div class="lp-suite-fp is-<?= $p[0] ?><?= $k === 0 ? ' is-on' : '' ?>" data-suite-panel<?= $k === 0 ? '' : ' aria-hidden="true"' ?>>
              <span class="lp-suite-fp-num"><?= e($suiteNum($k)) ?></span>
              <h3><?= e($p[2]) ?></h3>
              <p><?= e($p[3]) ?></p>
            </div>
            <?php endforeach; ?>
            <div class="lp-suite-progress" aria-hidden="true"><i data-suite-progress></i></div>
          </div>
          <div class="lp-suite-chips" role="group" aria-label="محصولات داتیس رایان">
            <?php foreach ($suite as $k => $p): ?>
            <button type="button" class="is-<?= $p[0] ?><?= $k === 0 ? ' is-on' : '' ?>" aria-pressed="<?= $k === 0 ? 'true' : 'false' ?>" data-suite-chip>
              <span class="lp-suite-chip-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $p[5] ?></svg></span><?= e($p[1]) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <div class="lp-suite-cta">
            <a href="#products" class="btn btn-primary">مشاهده‌ی محصولات</a>
            <a href="/contact.php" class="btn btn-ghost">درخواست مشاوره</a>
          </div>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide2" role="tabpanel" aria-roledescription="slide" aria-label="۲ از ۷">
        <div class="lp-banner-visual lp-banner-phone" aria-hidden="true">
          <picture>
            <source srcset="/assets/img/landing/banner-phone.webp" type="image/webp">
            <img src="/assets/img/landing/banner-phone.jpg" alt="" width="640" height="720" decoding="async">
          </picture>
          <svg class="lp-banner-streaks" viewBox="0 0 640 720" preserveAspectRatio="none">
            <path d="M-20 300C140 250 330 330 640 280"/><path d="M-20 360C170 330 360 420 640 360" style="animation-delay:1.1s"/><path d="M-20 420C120 420 300 470 640 450" style="animation-delay:2.2s"/>
          </svg>
        </div>
        <div class="lp-banner-copy">
          <h2>پیامک انبوه، <span class="is-blue">ساده</span> و <span class="is-green">قابل پیگیری</span></h2>
          <p>با داتیس رایان گستر ایرانیان پیام خود را از یک پنل به فهرست مخاطبانتان بفرستید و وضعیت تحویل هر پیامک را ببینید.</p>
          <div class="lp-banner-feats">
            <div><svg viewBox="0 0 24 24"><path d="M4 16a8 8 0 1 1 16 0"/><path d="m12 16 4-5"/><path d="M2 20h20"/></svg><b>ارسال مستقیم</b><span>ارسال فوری یا زمان‌بندی‌شده</span></div>
            <div><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg><b>پیگیری هر پیام</b><span>وضعیت تحویل هر پیامک</span></div>
            <div><svg viewBox="0 0 24 24"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg><b>مدیریت مخاطبین</b><span>گروه‌بندی و ورود از اکسل</span></div>
            <div><svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg><b>گزارش ارسال</b><span>وضعیت تحویل و خروجی گزارش</span></div>
          </div>
          <a href="<?= e($primaryHref ?? '/login.php') ?>" class="btn btn-primary">شروع ارسال</a>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide3" role="tabpanel" aria-roledescription="slide" aria-label="۳ از ۷">
        <div class="lp-banner-visual lp-banner-smart" aria-hidden="true">
          <div class="lp-smart-template">سلام <b>{نام}</b>، شما <b>{شانس}</b> شانس دارید</div>
          <div class="lp-smart-bubble" style="--i:0">سلام <b>علی</b>، شما <b>۲</b> شانس دارید <em>✓</em></div>
          <div class="lp-smart-bubble" style="--i:1">سلام <b>مریم</b>، شما <b>۵</b> شانس دارید <em>✓</em></div>
          <div class="lp-smart-bubble" style="--i:2">سلام <b>رضا</b>، شما <b>۱</b> شانس دارید <em>✓</em></div>
        </div>
        <div class="lp-banner-copy">
          <h2>پیامک <span class="is-blue">هوشمند</span>؛ هر نفر، پیام <span class="is-green">خودش</span></h2>
          <p>یک قالب بنویسید و فایل مخاطبین را بدهید؛ نام، مبلغ، کد یا تعداد شانس هر نفر خودکار در پیامش قرار می‌گیرد.</p>
          <ul class="lp-banner-points">
            <li>فایل اکسل یا CSV با هر تعداد ستون</li>
            <li>پیش‌نمایش پیام هر ردیف پیش از ارسال</li>
            <li>پیام شخصی برای هر ردیف فایل در یک کمپین</li>
          </ul>
          <a href="<?= e($primaryHref ?? '/login.php') ?>" class="btn btn-primary">امتحان پیامک هوشمند</a>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide4" role="tabpanel" aria-roledescription="slide" aria-label="۴ از ۷">
        <div class="lp-banner-visual lp-banner-report" aria-hidden="true">
          <div class="lp-report-card">
            <div class="lp-report-head"><span>نمونه — ارسال امروز</span><b>۱۲٬۴۸۰</b></div>
            <div class="lp-report-bars"><i style="--h:45%"></i><i style="--h:70%"></i><i style="--h:52%"></i><i style="--h:88%"></i><i style="--h:64%"></i><i style="--h:96%"></i><i style="--h:78%"></i></div>
            <div class="lp-report-row" style="--i:0"><span class="ltr">0912•••4471</span><em class="is-ok">تحویل شد</em></div>
            <div class="lp-report-row" style="--i:1"><span class="ltr">0935•••1187</span><em class="is-ok">تحویل شد</em></div>
            <div class="lp-report-row" style="--i:2"><span class="ltr">0919•••0032</span><em class="is-wait">در حال ارسال</em></div>
          </div>
        </div>
        <div class="lp-banner-copy">
          <h2>گزارش <span class="is-blue">لحظه‌ای</span> تحویل</h2>
          <p>پیشرفت هر ارسال حجیم و وضعیت تحویل تک‌تک پیامک‌ها را همان لحظه در داشبورد ببینید.</p>
          <ul class="lp-banner-points">
            <li>تحویل‌شده، ناموفق و در صف، جدا جدا</li>
            <li>نمودار ارسال هفت روز اخیر</li>
            <li>خروجی گزارش برای هر کمپین</li>
          </ul>
          <a href="#products" class="btn btn-ghost">مشاهده‌ی محصولات</a>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide5" role="tabpanel" aria-roledescription="slide" aria-label="۵ از ۷">
        <div class="lp-banner-visual lp-banner-voice" aria-hidden="true">
          <div class="lp-voice-card">
            <div class="lp-voice-head">
              <span class="lp-voice-orb"><i></i><i></i><i></i>
                <svg viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7"/></svg>
              </span>
              <div class="lp-voice-title"><b>پیام صوتی — جشنواره پاییزه</b><span>ارسال به ۸٬۲۰۰ شماره</span></div>
              <time class="lp-voice-time">۰:۲۴</time>
            </div>
            <div class="lp-voice-wave">
              <?php foreach ([30, 52, 74, 46, 88, 62, 38, 70, 96, 58, 42, 80, 64, 34, 76, 92, 54, 40, 68, 84, 48, 72, 36, 60, 90, 56, 44, 78, 50, 32, 66, 40] as $k => $h): ?>
                <i style="--h:<?= $h ?>%;--k:<?= $k ?>"></i>
              <?php endforeach; ?>
            </div>
            <div class="lp-voice-row" style="--i:0"><span class="ltr">021•••4410</span><em class="is-ok">شنیده شد</em></div>
            <div class="lp-voice-row" style="--i:1"><span class="ltr">0912•••7731</span><em class="is-live"><s><i></i><i></i><i></i></s>در حال پخش</em></div>
            <div class="lp-voice-row" style="--i:2"><span class="ltr">031•••2280</span><em class="is-wait">در صف تماس</em></div>
          </div>
        </div>
        <div class="lp-banner-copy">
          <h2>پیام <span class="is-blue">صوتی</span>؛ صدای شما، روی <span class="is-green">هر تلفنی</span></h2>
          <p>پیامتان را ضبط کنید و با یک تماس خودکار به گوش مخاطبان برسانید؛ روی خط ثابت و همراه، بدون نیاز به اینترنت.</p>
          <ul class="lp-banner-points">
            <li>ارسال به تلفن ثابت و همراه</li>
            <li>زمان‌بندی تماس در ساعت‌های مناسب</li>
            <li>گزارش تماس‌های پاسخ‌داده‌شده</li>
          </ul>
          <a href="<?= e($primaryHref ?? '/login.php') ?>" class="btn btn-primary">شروع پیام صوتی</a>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide6" role="tabpanel" aria-roledescription="slide" aria-label="۶ از ۷">
        <div class="lp-banner-visual lp-banner-bale" aria-hidden="true">
          <div class="lp-bale-chat">
            <div class="lp-bale-head">
              <span class="lp-bale-avatar"><svg viewBox="0 0 24 24"><path d="M5 8h14l-1.2 11a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg></span>
              <div><b>فروشگاه نمونه <svg class="lp-bale-verified" viewBox="0 0 24 24"><path d="m12 2 2.4 2.1 3.2-.3.9 3.1 2.8 1.6-1.1 3 1.1 3-2.8 1.6-.9 3.1-3.2-.3L12 22l-2.4-2.1-3.2.3-.9-3.1-2.8-1.6 1.1-3-1.1-3 2.8-1.6.9-3.1 3.2.3Z"/><path class="is-tick" d="m8.5 12 2.4 2.4 4.6-4.8"/></svg></b><span>حساب کسب‌وکار در بله</span></div>
            </div>
            <div class="lp-bale-body">
              <span class="lp-bale-day">امروز</span>
              <p class="lp-bale-typing"><i></i><i></i><i></i></p>
              <p class="lp-bale-msg" style="--i:0">سلام سارا جان 👋 سفارش <b>۴۸۲۱</b> شما ارسال شد و فردا به دستتان می‌رسد.
                <small>۱۰:۲۴ <svg viewBox="0 0 24 16"><path d="m1 8.5 4 4 8-9"/><path class="is-second" d="m7 10.5 2 2 8-9"/></svg></small>
              </p>
              <p class="lp-bale-msg" style="--i:1">کد رهگیری مرسوله: <b class="ltr">EL-21794</b>
                <small>۱۰:۲۴ <svg viewBox="0 0 24 16"><path d="m1 8.5 4 4 8-9"/><path class="is-second" d="m7 10.5 2 2 8-9"/></svg></small>
              </p>
              <span class="lp-bale-btn">پیگیری سفارش</span>
            </div>
          </div>
          <div class="lp-bale-chip is-a"><b>۸۴٪</b>تحویل در بله</div>
          <div class="lp-bale-chip is-b"><b>۱۶٪</b>پیامک جایگزین</div>
        </div>
        <div class="lp-banner-copy">
          <h2>پیام در <span class="is-blue">بله</span>؛ ارزان‌تر از پیامک، <span class="is-green">بی‌واسطه</span></h2>
          <p>پیام‌های اطلاع‌رسانی و کد تأیید را مستقیم به حساب بله‌ی مخاطب بفرستید؛ برای هر کس که در بله نبود، پیامک جایش را می‌گیرد.</p>
          <ul class="lp-banner-points">
            <li>ارسال به شماره‌ی موبایل، بدون نیاز به عضویت در کانال</li>
            <li>پیامک جایگزین خودکار برای بقیه</li>
            <li>هزینه فقط برای پیام‌های تحویل‌شده در هر کانال</li>
          </ul>
          <a href="<?= e($primaryHref ?? '/login.php') ?>" class="btn btn-primary">ارسال در بله</a>
        </div>
      </article>

      <article class="lp-banner-slide" id="lpSlide7" role="tabpanel" aria-roledescription="slide" aria-label="۷ از ۷">
        <div class="lp-banner-visual lp-banner-ai" aria-hidden="true">
          <div class="lp-ai-chat">
            <div class="lp-ai-chat-head">
              <span class="lp-ai-orb"><i></i><i></i><svg viewBox="0 0 24 24"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/></svg></span>
              <div><b>منشی هوشمند</b><span>پاسخ خودکار روی خط شما</span></div>
              <em class="lp-ai-live">آنلاین</em>
            </div>
            <div class="lp-ai-chat-body">
              <p class="lp-ai-msg is-in lp-ai-pop" style="--t:.5s">سلام، کیف چرمی قهوه‌ای موجوده؟ قیمتش چنده؟<small>۲۳:۴۸</small></p>
              <p class="lp-ai-typing" style="--t:1.3s"><i></i><i></i><i></i></p>
              <p class="lp-ai-msg is-out lp-ai-pop" style="--t:2.9s">سلام! بله موجود است 🌿 قیمت <b>۱٬۸۵۰٬۰۰۰ تومان</b>. برای سفارش، کد <b>۲۱۴</b> را بفرستید.<small>۲۳:۴۸ · منشی هوشمند</small></p>
            </div>
          </div>
          <div class="lp-ai-chip is-a lp-ai-pop" style="--t:3.6s"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/></svg>از جدول «محصولات»</div>
          <div class="lp-ai-chip is-b lp-ai-pop" style="--t:4s"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>شبانه‌روزی</div>
        </div>
        <div class="lp-banner-copy">
          <h2>منشی <span class="is-blue">هوشمند</span>؛ پاسخ‌گوی <span class="is-green">شبانه‌روزی</span> مشتریان</h2>
          <p>هوش مصنوعی به پیامک‌هایی که به خط شما می‌رسد، از روی اطلاعات کسب‌وکار و جدول‌های خودتان جواب می‌دهد؛ از همان خط و در چند ثانیه.</p>
          <ul class="lp-banner-points">
            <li>جواب از قیمت‌ها، سفارش‌ها و نوبت‌های خودتان</li>
            <li>ارجاع گفتگو به اپراتور، هر وقت لازم شد</li>
            <li>پیامک و تلگرام، با یک منشی</li>
          </ul>
          <a href="/guide.php#guide-ai-secretary" class="btn btn-primary">آشنایی با منشی هوشمند</a>
        </div>
      </article>

    </div>
    <div class="lp-banner-controls">
      <button type="button" class="lp-banner-toggle" data-lp-banner-toggle aria-pressed="false">
        <span class="lp-banner-toggle-icon" aria-hidden="true"></span><span data-lp-banner-toggle-label>توقف اسلایدها</span>
      </button>
      <div class="lp-banner-dots" role="tablist" aria-label="اسلایدها">
        <button type="button" role="tab" id="lpSlideTab1" aria-controls="lpSlide1" aria-selected="true" aria-label="اسلاید ۱" class="is-active"></button>
        <button type="button" role="tab" id="lpSlideTab2" aria-controls="lpSlide2" aria-selected="false" aria-label="اسلاید ۲" tabindex="-1"></button>
        <button type="button" role="tab" id="lpSlideTab3" aria-controls="lpSlide3" aria-selected="false" aria-label="اسلاید ۳" tabindex="-1"></button>
        <button type="button" role="tab" id="lpSlideTab4" aria-controls="lpSlide4" aria-selected="false" aria-label="اسلاید ۴" tabindex="-1"></button>
        <button type="button" role="tab" id="lpSlideTab5" aria-controls="lpSlide5" aria-selected="false" aria-label="اسلاید ۵" tabindex="-1"></button>
        <button type="button" role="tab" id="lpSlideTab6" aria-controls="lpSlide6" aria-selected="false" aria-label="اسلاید ۶" tabindex="-1"></button>
        <button type="button" role="tab" id="lpSlideTab7" aria-controls="lpSlide7" aria-selected="false" aria-label="اسلاید ۷" tabindex="-1"></button>
      </div>
    </div>
  </section>
<script>
(function () {
  // Banner carousel. Auto-advance pauses while hovered, while anything inside has keyboard focus,
  // while off-screen, and for good once the visitor presses "توقف" (WCAG 2.2.2). The dots are an
  // ARIA tablist: arrow keys / Home / End move between slides, only the current tab is in the tab order.
  var root = document.querySelector('[data-lp-banner]');
  if (!root) return;
  // The banner fills the screen below the sticky nav; expose the nav's real height to the CSS.
  var nav = document.querySelector('.lp-nav');
  function setNavHeight() { if (nav) document.documentElement.style.setProperty('--lp-nav-h', nav.offsetHeight + 'px'); }
  setNavHeight();
  window.addEventListener('resize', setNavHeight);
  var slides = root.querySelectorAll('.lp-banner-slide');
  var dots = root.querySelectorAll('.lp-banner-dots [role="tab"]');
  var toggle = root.querySelector('[data-lp-banner-toggle]');
  var toggleLabel = root.querySelector('[data-lp-banner-toggle-label]');
  var current = 0, timer = null, hovering = false, focused = false, visible = true;
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var stopped = still;
  function show(i, moveFocus) {
    current = (i + slides.length) % slides.length;
    slides.forEach(function (s, k) { s.classList.toggle('is-active', k === current); s.setAttribute('aria-hidden', k === current ? 'false' : 'true'); });
    dots.forEach(function (d, k) {
      var on = k === current;
      d.classList.toggle('is-active', on);
      d.setAttribute('aria-selected', on ? 'true' : 'false');
      d.tabIndex = on ? 0 : -1;
    });
    if (moveFocus) dots[current].focus();
  }
  function schedule() {
    clearTimeout(timer);
    if (stopped || hovering || focused || !visible || document.hidden) return;
    // A slide may ask for longer with data-duration (ms), e.g. slide 1's opening animation.
    timer = setTimeout(function () { show(current + 1); schedule(); }, +slides[current].getAttribute('data-duration') || 7000);
  }
  function setStopped(on) {
    stopped = on;
    root.classList.toggle('is-stopped', on);
    if (toggle) {
      toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      toggleLabel.textContent = on ? 'پخش اسلایدها' : 'توقف اسلایدها';
    }
    schedule();
  }
  dots.forEach(function (d, k) {
    d.addEventListener('click', function () { show(k); schedule(); });
    d.addEventListener('keydown', function (e) {
      // RTL: the visually "next" dot is to the left.
      var step = { ArrowLeft: 1, ArrowRight: -1 }[e.key];
      if (step) { e.preventDefault(); show(current + step, true); }
      else if (e.key === 'Home') { e.preventDefault(); show(0, true); }
      else if (e.key === 'End') { e.preventDefault(); show(slides.length - 1, true); }
    });
  });
  if (toggle) toggle.addEventListener('click', function () { setStopped(!stopped); });
  root.addEventListener('mouseenter', function () { hovering = true; schedule(); });
  root.addEventListener('mouseleave', function () { hovering = false; schedule(); });
  root.addEventListener('focusin', function () { focused = true; schedule(); });
  root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) { focused = false; schedule(); } });
  var x0 = null;
  root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 40) { show(current + (dx > 0 ? 1 : -1)); schedule(); } // RTL: swipe right = next
  });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      root.classList.toggle('is-paused', !visible);
      schedule();
    }).observe(root);
  }
  document.addEventListener('visibilitychange', schedule);
  show(0); setStopped(stopped);
})();
</script>
<script>
(function () {
  // Banner slide 1: the five products on a tilted orbit (docs/landing.md, «Slide 1»). Positions are
  // computed here every frame from one angle (phi) and written as transforms in cqw of the stage;
  // the loop runs only while the slide is active. Auto-stepping stops with the banner's «توقف» and
  // for reduced motion (the chips still work, without the tween).
  var slide = document.querySelector('[data-lp-suite]');
  if (!slide) return;
  var banner = slide.closest('[data-lp-banner]');
  var stage = slide.querySelector('[data-suite-stage]');
  var planets = slide.querySelectorAll('[data-suite-planet]');
  var beams = slide.querySelectorAll('[data-suite-beam]');
  var sparks = slide.querySelectorAll('[data-suite-spark]');
  var cards = slide.querySelectorAll('[data-suite-card]');
  var panels = slide.querySelectorAll('[data-suite-panel]');
  var chips = slide.querySelectorAll('[data-suite-chip]');
  var bar = slide.querySelector('[data-suite-progress]');
  var N = planets.length, STEP = 360 / N;
  var CX = 50, CY = 38, RX = 36, RY = 17;             // orbit, in cqw (the SVG uses the same numbers)
  var OPEN = 2100, DWELL = 2600, HOLD = 6000, TWEEN = 1100, SWIRL = 850;
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var wasFront = [];
  function burst(el) { var b = el.querySelector('.lp-suite-burst'); b.classList.remove('is-go'); void b.offsetWidth; b.classList.add('is-go'); }
  var focus = 0, phi = 90, phiFrom = 90, phiTo = 90, tweenAt = -1;
  var clock = 0, last = 0, dwellAt = OPEN, dwell = DWELL, raf = 0;

  function ease(t) { return t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }
  function easeOut(t) { return 1 - Math.pow(1 - t, 3); }
  function back(t) { var c = 1.6; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); }
  function clamp(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
  function paused() { return banner.classList.contains('is-stopped') || banner.classList.contains('is-paused') || document.hidden; }

  function setFocus(k, byUser) {
    k = (k + N) % N;
    var target = 90 + k * STEP;
    var delta = ((target - phi) % 360 + 360) % 360;   // auto: always turn the same way
    if (byUser && delta > 180) delta -= 360;          // a click takes the short way round
    focus = k;
    if (still) { phi = phiTo = phi + delta; tweenAt = -1; }
    else { phiFrom = phi; phiTo = phi + delta; tweenAt = performance.now(); }
    dwellAt = Math.max(clock, OPEN); dwell = byUser ? HOLD : DWELL;
    panels.forEach(function (p, i) { p.classList.toggle('is-on', i === k); if (i === k) p.removeAttribute('aria-hidden'); else p.setAttribute('aria-hidden', 'true'); });
    chips.forEach(function (c, i) { c.classList.toggle('is-on', i === k); c.setAttribute('aria-pressed', i === k ? 'true' : 'false'); });
    if (still) render();
  }

  function render() {
    var open = still ? 1 : clock / OPEN;
    var spin = still ? 1 : clamp((clock - SWIRL + 200) / (OPEN - SWIRL + 200));
    var swirl = still ? 0 : 160 * (1 - easeOut(spin));
    var fx = CX, fy = CY;
    for (var k = 0; k < N; k++) {
      var r = still ? 1 : back(clamp((clock - SWIRL - k * 120) / 750));
      var th = (phi - swirl - k * STEP) * Math.PI / 180;
      var s = Math.sin(th), d = (s + 1) / 2;
      var x = CX + RX * r * Math.cos(th), y = CY + RY * r * s;
      var boost = Math.pow(Math.max(0, s), 12) * .26;
      var sc = (.56 + .44 * d + boost) * (.25 + .75 * clamp(r));
      var el = planets[k];
      el.style.transform = 'translate(' + x.toFixed(2) + 'cqw,' + y.toFixed(2) + 'cqw) translate(-50%,-50%) scale(' + sc.toFixed(3) + ')';
      el.style.zIndex = s < 0 ? 1 + Math.round(d) : 6 + Math.round(d * 4);
      el.style.opacity = ((.7 + .3 * d) * clamp(r * 1.6)).toFixed(3);
      el.style.setProperty('--d', d.toFixed(3));
      var front = k === focus && s > .97;
      if (open >= 1) { if (front && !wasFront[k] && !still) burst(el); wasFront[k] = front; }   // arrival burst
      el.classList.toggle('is-focus', front);
      cards[k].classList.toggle('is-on', front && open >= 1);
      beams[k].setAttribute('x2', x.toFixed(2)); beams[k].setAttribute('y2', y.toFixed(2));
      beams[k].style.opacity = (clamp(r) * (k === focus ? 1 : .18 + .25 * d)).toFixed(3);
      beams[k].classList.toggle('is-hot', k === focus);
      if (k === focus) { fx = x; fy = y; }
    }
    sparks.forEach(function (sp, i) {   // two sparks running down the hot beam
      var t = ((clock / 1300) + i * .5) % 1;
      sp.setAttribute('cx', (CX + (fx - CX) * t).toFixed(2)); sp.setAttribute('cy', (CY + (fy - CY) * t).toFixed(2));
      sp.style.opacity = still || open < 1 ? 0 : (Math.sin(t * Math.PI)).toFixed(3);
    });
    if (bar) bar.style.transform = 'scaleX(' + (still ? 0 : clamp((clock - dwellAt) / dwell)).toFixed(3) + ')';
  }

  function frame(now) {
    raf = requestAnimationFrame(frame);
    var dt = last ? Math.min(now - last, 100) : 0; last = now;
    if (!paused()) clock += dt;
    if (tweenAt >= 0) {
      var t = clamp((now - tweenAt) / TWEEN);
      phi = phiFrom + (phiTo - phiFrom) * ease(t);
      if (t >= 1) tweenAt = -1;
    }
    if (!paused() && clock >= OPEN && clock - dwellAt >= dwell) setFocus(focus + 1, false);
    render();
  }

  function start() {
    cancelAnimationFrame(raf);
    clock = 0; last = 0; phi = phiFrom = phiTo = 90; tweenAt = -1; wasFront = [];
    setFocus(0, false); dwellAt = OPEN; dwell = DWELL;
    if (still) { clock = OPEN; render(); return; }
    raf = requestAnimationFrame(frame);
  }
  function stop() { cancelAnimationFrame(raf); raf = 0; }

  chips.forEach(function (c, i) { c.addEventListener('click', function () { setFocus(i, true); }); });
  new MutationObserver(function () {
    if (slide.classList.contains('is-active')) { if (!raf) start(); } else stop();
  }).observe(slide, { attributes: true, attributeFilter: ['class'] });

  // Mouse parallax (desktop pointers only): the stage tilts towards the pointer.
  if (!still && window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    var vis = slide.querySelector('.lp-suite');
    vis.addEventListener('pointermove', function (e) {
      var b = vis.getBoundingClientRect();
      stage.style.setProperty('--tx', ((e.clientX - b.left) / b.width - .5).toFixed(3));
      stage.style.setProperty('--ty', ((e.clientY - b.top) / b.height - .5).toFixed(3));
    });
    vis.addEventListener('pointerleave', function () { stage.style.setProperty('--tx', 0); stage.style.setProperty('--ty', 0); });
  }

  if (slide.classList.contains('is-active')) start(); else render();
})();
</script>

  <div class="lp-scroll-progress" aria-hidden="true"><div class="lp-scroll-progress-bar" id="lpScrollBar"></div></div>

  <section class="lp-hero">
    <div class="lp-hero-inner">
      <div class="lp-hero-copy">
        <p class="lp-eyebrow">پنل هوشمند پیامک</p>
        <h1>ارسال پیامک انبوه، <span class="lp-heading-accent">شخصی‌سازی‌شده</span> و خودکار<br>همه در یک پنل</h1>
        <p class="lp-hero-sub">
          از ارسال ساده و زمان‌بندی‌شده تا پیامک هوشمند با قالب پویا، منشی هوشمند با هوش مصنوعی
          و گزارش لحظه‌ای وضعیت هر پیام — داتیس رایان گستر ایرانیان ابزار پیامک‌رسانی کسب‌وکار شماست.
        </p>
        <div class="lp-hero-cta">
          <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
          <a href="#products" class="btn btn-ghost">مشاهده‌ی محصولات</a>
        </div>
        <ul class="lp-hero-stats">
          <li><strong>۵</strong><span>حالت ارسال</span></li>
          <li><strong>قالب پویا</strong><span>با ستون‌های فایل شما</span></li>
          <li><strong>زمان‌بندی</strong><span>با تقویم شمسی</span></li>
          <li><strong>زرین‌پال</strong><span>پرداخت آنلاین</span></li>
        </ul>
      </div>
      <div class="lp-hero-visual" aria-hidden="true">
        <!-- The 3D phone scene from the ELLSMS promo video, kept as one still (≈40 KB WebP) with the
             motion added in CSS: flying envelopes, the orbit ring, the podium glow and sparks. The
             captions are real text below (the video's own Persian captions were unreadable). -->
        <div class="lp-scene" data-lp-scene>
          <picture>
            <source srcset="/assets/img/landing/hero-scene.webp" type="image/webp">
            <img class="lp-scene-img" src="/assets/img/landing/hero-scene.jpg" alt="" width="760" height="720" fetchpriority="high" decoding="async">
          </picture>
          <span class="lp-scene-glow"></span>
          <svg class="lp-scene-orbit" viewBox="0 0 760 720" preserveAspectRatio="none">
            <g transform="rotate(-7 380 470)">
              <ellipse class="lp-orbit-track" cx="380" cy="470" rx="275" ry="60"/>
              <ellipse class="lp-orbit-comet" cx="380" cy="470" rx="275" ry="60" pathLength="1000"/>
            </g>
          </svg>
          <span class="lp-scene-mail" style="--x:-165px;--y:-150px;--r:-22deg;--d:0s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-mail" style="--x:170px;--y:-130px;--r:18deg;--d:0.8s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-mail" style="--x:-205px;--y:20px;--r:-10deg;--d:1.6s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-mail" style="--x:190px;--y:40px;--r:14deg;--d:2.4s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-mail" style="--x:-120px;--y:150px;--r:-16deg;--d:3.2s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-mail" style="--x:140px;--y:165px;--r:12deg;--d:4.0s"><svg viewBox="0 0 40 28"><rect x="1" y="1" width="38" height="26" rx="4"/><path d="M2 3l18 13L38 3"/></svg></span>
          <span class="lp-scene-spark" style="--l:18%;--d:0s"></span>
          <span class="lp-scene-spark" style="--l:32%;--d:1.2s"></span>
          <span class="lp-scene-spark" style="--l:47%;--d:2.6s"></span>
          <span class="lp-scene-spark" style="--l:63%;--d:0.6s"></span>
          <span class="lp-scene-spark" style="--l:78%;--d:1.9s"></span>
          <span class="lp-scene-spark" style="--l:26%;--d:3.1s"></span>
          <span class="lp-scene-spark" style="--l:55%;--d:3.8s"></span>
          <span class="lp-scene-spark" style="--l:84%;--d:0.3s"></span>
          <span class="lp-scene-spark" style="--l:40%;--d:2.2s"></span>
          <span class="lp-scene-spark" style="--l:70%;--d:4.4s"></span>
        </div>
        <div class="lp-scene-cards">
          <div class="lp-scene-card" style="--d:0s">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 16a8 8 0 1 1 16 0"/><path d="m12 16 4-5"/><path d="M2 20h20"/></svg>
            <span>ارسال زمان‌بندی‌شده</span>
          </div>
          <div class="lp-scene-card" style="--d:2s">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
            <span>ورود دومرحله‌ای</span>
          </div>
          <div class="lp-scene-card" style="--d:4s">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
            <span>گزارش لحظه‌ای</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- «محصولات و خدمات»: the five product lines of Datis Rayan (docs/landing.md#products). Each line
       is an <article> with a text side and an animated SVG scene (no images, no library). Scenes
       animate only while on screen (.is-live, script below) and are still for reduced motion.
       Styles: public/assets/css/landing-products.css. -->
  <?php
  $productLines = [
      [
          'id' => 'product-sms', 'tone' => 'sms', 'scene' => 'sms',
          'tag' => 'محصول اصلی', 'title' => 'سامانه پیامکی و بازاریابی',
          'lead' => 'پنل پیامک تحت وب برای ارسال انبوه، شخصی‌سازی‌شده و خودکار؛ از یک پیام تبلیغاتی ساده تا کمپین‌های هدفمند با قالب پویا، زمان‌بندی با تقویم شمسی، منشی هوشمند با هوش مصنوعی و گزارش لحظه‌ای تحویل هر پیام. همه‌ی ابزارهای بازاریابی پیامکی کسب‌وکار شما در یک پنل.',
          'items' => [
              ['ارسال انبوه و هدفمند', 'ارسال به فایل مخاطبین، گروه‌ها، ارسال منطقه‌ای و نظیربه‌نظیر با خط اختصاصی.', 'send'],
              ['پیامک هوشمند با قالب پویا', 'یک قالب بنویسید و برای هر ردیف فایل، پیامی با نام و مبلغ و کد خودش بفرستید.', 'spark'],
              ['زمان‌بندی و ارسال دوره‌ای', 'کمپین‌های تولد، یادآوری قسط و پیام‌های تکرارشونده با تقویم شمسی.', 'clock'],
              ['منشی هوشمند و وب‌سرویس', 'پاسخ خودکار با هوش مصنوعی، API و SDK برای اتصال سایت و نرم‌افزار شما.', 'bot'],
          ],
          'cta' => 'panel',
      ],
      [
          'id' => 'product-cloud', 'tone' => 'cloud', 'scene' => 'cloud',
          'tag' => 'زیرساخت', 'title' => 'خدمات ابری و زیرساختی',
          'lead' => 'زیرساخت پایدار و مقیاس‌پذیر برای میزبانی سایت، اپلیکیشن و داده‌های کسب‌وکار شما. از یک هاست ساده برای وب‌سایت شرکتی تا سرور مجازی قدرتمند و فضای ذخیره‌سازی ابری امن — با منابع قابل ارتقا و پشتیبانی فنی کارشناسان داتیس رایان.',
          'items' => [
              ['سرور مجازی (VPS)', 'منابع اختصاصی پردازنده، حافظه و دیسک SSD، دسترسی کامل root و انتخاب سیستم‌عامل.', 'server'],
              ['هاست و دامنه', 'هاست لینوکسی و وردپرسی با کنترل‌پنل، گواهی SSL و ثبت و مدیریت دامنه‌ی ملی و بین‌المللی.', 'globe'],
              ['فضای ذخیره‌سازی ابری', 'نگهداری امن فایل‌ها و نسخه‌های پشتیبان با امکان افزایش ظرفیت هر زمان که لازم شد.', 'db'],
              ['پایش و پشتیبان‌گیری', 'نظارت بر سلامت سرویس‌ها و پشتیبان‌گیری منظم برای آسودگی خاطر شما.', 'pulse'],
          ],
          'cta' => 'contact',
      ],
      [
          'id' => 'product-ai', 'tone' => 'ai', 'scene' => 'ai',
          'tag' => 'هوش مصنوعی', 'title' => 'هوش مصنوعی و اتوماسیون',
          'lead' => 'کارهای تکراری را به هوش مصنوعی بسپارید و زمان تیم‌تان را صرف کارهای مهم‌تر کنید. محتوای تبلیغاتی و پیام‌های کمپین را در چند ثانیه به فارسی روان بسازید و فرآیندهای کسب‌وکار — از ثبت سفارش تا پیگیری مشتری — را خودکار کنید.',
          'items' => [
              ['تولید محتوا با هوش مصنوعی', 'متن تبلیغاتی، پیامک کمپین، پست شبکه‌های اجتماعی و توضیح محصول، متناسب با لحن برند شما.', 'pen'],
              ['اتوماسیون فرآیندهای کسب‌وکار', 'گردش‌کارهای خودکار بین فرم، فروشگاه، CRM و پیامک؛ بدون کار دستی و خطای انسانی.', 'flow'],
              ['دستیار و چت‌بات هوشمند', 'پاسخ‌گویی شبانه‌روزی به مشتریان از روی اطلاعات و جدول‌های کسب‌وکار شما.', 'bot'],
              ['گزارش و تحلیل خودکار', 'خلاصه‌ی روزانه‌ی فروش، پیام‌ها و عملکرد، بدون ساختن دستی گزارش.', 'chart'],
          ],
          'cta' => 'contact',
      ],
      [
          'id' => 'product-it', 'tone' => 'it', 'scene' => 'it',
          'tag' => 'خدمات فنی', 'title' => 'خدمات فناوری اطلاعات',
          'lead' => 'یک تیم فنی کامل، بدون هزینه‌ی استخدام. کارشناسان داتیس رایان شبکه، سرورها و نرم‌افزارهای سازمان شما را راه‌اندازی می‌کنند، سالم نگه می‌دارند و در برابر تهدیدها ایمن می‌کنند؛ تا شما فقط روی کسب‌وکارتان تمرکز کنید.',
          'items' => [
              ['پشتیبانی شبکه', 'طراحی، راه‌اندازی و عیب‌یابی شبکه‌ی سیمی و بی‌سیم دفتر و شعب، حضوری و از راه دور.', 'net'],
              ['نصب و راه‌اندازی سرور', 'نصب و پیکربندی سرورهای ویندوز و لینوکس، مجازی‌سازی و سرویس‌های سازمانی.', 'server'],
              ['نگهداری و پشتیبانی نرم‌افزار', 'به‌روزرسانی، رفع اشکال و پشتیبانی مستمر نرم‌افزارها و سامانه‌های سازمان.', 'wrench'],
              ['مشاوره‌ی امنیت زیرساخت', 'ارزیابی آسیب‌پذیری، سخت‌سازی سرورها، فایروال و سیاست‌های دسترسی امن.', 'shield'],
          ],
          'cta' => 'contact',
      ],
      [
          'id' => 'product-voip', 'tone' => 'voip', 'scene' => 'voip',
          'tag' => 'ارتباطات', 'title' => 'تلفن و ارتباطات سازمانی',
          'lead' => 'سیستم تلفن سازمانی مبتنی بر VoIP؛ تماس‌های داخلی و شعب روی بستر اینترنت، منشی تلفنی گویا و صف تماس برای اینکه هیچ تماس مشتری بی‌پاسخ نماند. داخلی‌ها روی گوشی رومیزی، موبایل یا کامپیوتر، هر جا که تیم شما باشد.',
          'items' => [
              ['سانترال تحت شبکه (VoIP)', 'راه‌اندازی مرکز تلفن IP، تعریف داخلی‌ها و اتصال به خطوط شهری و سیپ‌ترانک.', 'phone'],
              ['منشی تلفنی و صف تماس', 'پیام خوشامد، منوی گویا («برای فروش ۱ را بگیرید») و توزیع هوشمند تماس‌ها بین کارشناسان.', 'menu'],
              ['اتصال شعب و دورکاری', 'تماس رایگان بین دفاتر و داخلی روی موبایل برای همکارانی که بیرون از دفترند.', 'branch'],
              ['ضبط و گزارش مکالمات', 'ضبط تماس‌ها، گزارش تماس‌های ازدست‌رفته و عملکرد هر داخلی برای کنترل کیفیت.', 'rec'],
          ],
          'cta' => 'contact',
      ],
  ];
  $prodIcons = [
      'send'   => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/>',
      'spark'  => '<path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>',
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
      'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 13l-8 8-3-3 8-8-1.3-1.3a4 4 0 0 1-5-5L13 2l1.7 4.3Z" transform="scale(-1 1) translate(-24 0)"/>',
      'shield' => '<path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
      'phone'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
      'menu'   => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
      'branch' => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>',
      'rec'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5" fill="currentColor"/>',
  ];
  $prodIcon = static fn (string $k): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $prodIcons[$k] . '</svg>';
  // Scenes are rendered in their own scope so their loop variables cannot overwrite $p/$k here.
  $renderProdScene = static function (string $file): void { require $file; };
  $prodNavIcon = ['sms' => 'send', 'cloud' => 'server', 'ai' => 'spark', 'it' => 'shield', 'voip' => 'phone'];
  ?>
  <section id="products" class="lp-section lp-prod-section" aria-labelledby="lpProdTitle">
    <div class="lp-section-head lp-prod-head lp-reveal">
      <span class="lp-prod-eyebrow">محصولات و خدمات</span>
      <h2 id="lpProdTitle">یک شریک فناوری برای <span class="lp-heading-accent">همه‌ی نیازهای</span> کسب‌وکار شما</h2>
      <p>از پیامک و بازاریابی تا زیرساخت ابری، هوش مصنوعی، پشتیبانی فناوری اطلاعات و تلفن سازمانی — داتیس رایان گستر ایرانیان همه را یکجا، با یک تیم پشتیبانی ارائه می‌دهد.</p>
    </div>

    <nav class="lp-prod-nav lp-reveal" aria-label="فهرست محصولات">
      <?php foreach ($productLines as $k => $p): ?>
      <a href="#<?= e($p['id']) ?>" class="lp-prod-pill is-<?= e($p['tone']) ?>" style="--i:<?= $k ?>">
        <span class="lp-prod-pill-ico"><?= $prodIcon($prodNavIcon[$p['tone']]) ?></span>
        <span><?= e($p['title']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="lp-prod-list">
      <?php foreach ($productLines as $k => $p): $n = $k + 1; ?>
      <article id="<?= e($p['id']) ?>" class="lp-prod is-<?= e($p['tone']) ?><?= $k % 2 ? ' is-flip' : '' ?> lp-reveal" aria-labelledby="<?= e($p['id']) ?>-t">
        <div class="lp-prod-visual">
          <div class="lp-prod-scene" data-lp-prod-scene aria-hidden="true">
            <?php $renderProdScene(__DIR__ . '/../app/views/landing_product_scenes/' . $p['scene'] . '.php'); ?>
          </div>
        </div>
        <div class="lp-prod-body">
          <div class="lp-prod-meta">
            <span class="lp-prod-num"><?= e(['۰۱', '۰۲', '۰۳', '۰۴', '۰۵'][$k]) ?></span>
            <span class="lp-prod-tag"><?= e($p['tag']) ?></span>
          </div>
          <h3 id="<?= e($p['id']) ?>-t"><?= e($p['title']) ?></h3>
          <p class="lp-prod-lead"><?= e($p['lead']) ?></p>
          <ul class="lp-prod-items">
            <?php foreach ($p['items'] as $j => [$t, $d, $ic]): ?>
            <li style="--j:<?= $j ?>">
              <span class="lp-prod-ico"><?= $prodIcon($ic) ?></span>
              <div><strong><?= e($t) ?></strong><span><?= e($d) ?></span></div>
            </li>
            <?php endforeach; ?>
          </ul>
          <div class="lp-prod-cta">
            <?php if ($p['cta'] === 'panel'): ?>
            <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
            <?php else: ?>
            <a href="/contact.php" class="btn btn-primary">درخواست مشاوره و قیمت</a>
            <a href="/contact.php" class="btn btn-ghost">تماس با کارشناسان</a>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
<script>
(function () {
  // Product scenes animate only while on screen; with no IntersectionObserver they just run.
  var scenes = document.querySelectorAll('[data-lp-prod-scene]');
  if (!('IntersectionObserver' in window)) { scenes.forEach(function (s) { s.classList.add('is-live'); }); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { en.target.classList.toggle('is-live', en.isIntersecting); });
  }, { threshold: 0.2 });
  scenes.forEach(function (s) { io.observe(s); });
})();
</script>

<script>
(function () {
  // Hero scene: CSS-only motion, paused while the hero is off-screen.
  var scene = document.querySelector('[data-lp-scene]');
  if (scene && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      scene.classList.toggle('is-paused', !entries[0].isIntersecting);
    }).observe(scene);
  }
})();
</script>

  <section id="customers" class="lp-section lp-customers lp-reveal">
    <div class="lp-section-head">
      <h2>مشتریان و همکاران</h2>
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

  <section id="trust" class="lp-section lp-trust lp-reveal">
    <div class="lp-section-head">
      <h2>ساخته‌شده برای اطمینان</h2>
    </div>
    <div class="lp-grid lp-grid-narrow">
      <div class="lp-trust-item">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        </span>
        <h4>راست‌به‌چپ و تقویم شمسی</h4>
        <p>تمام پنل فارسی و راست‌به‌چپ است؛ تاریخ‌ها با تقویم جلالی و اعداد فارسی نمایش داده می‌شوند.</p>
      </div>
      <div class="lp-trust-item">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="10" width="18" height="10" rx="2"/><path d="M7 10V7a5 5 0 0 1 10 0v3"/></svg>
        </span>
        <h4>پرداخت امن با زرین‌پال</h4>
        <p>خرید اعتبار مستقیماً از طریق API رسمی زرین‌پال انجام می‌شود؛ هر پرداخت فقط یک‌بار اعتبار می‌دهد.</p>
      </div>
      <div class="lp-trust-item">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
        </span>
        <h4>پردازش پیوسته در پس‌زمینه</h4>
        <p>زمان‌بندی‌ها، منشی پیامک و ارسال‌های انبوه توسط یک پردازشگر پیوسته دنبال می‌شوند، نه فقط هنگام باز بودن مرورگر.</p>
      </div>
      <div class="lp-trust-item">
        <span class="lp-trust-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h4>مدیریت متمرکز کاربران</h4>
        <p>مدیر می‌تواند دسترسی، اعتبار و شماره‌های اختصاصی هر کاربر را از یک‌جا کنترل کند.</p>
      </div>
    </div>
  </section>

  <section id="faq" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>سوالات پرتکرار</h2>
    </div>
    <div class="lp-guide-list">
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

  <section class="lp-cta lp-reveal">
    <h2>آماده‌اید شروع کنید؟</h2>
    <p>به پنل وارد شوید و اولین ارسال خود را انجام دهید.</p>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
