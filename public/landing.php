<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
// A reseller's own host (app/ResellerPanel.php) has no platform marketing site: it opens on the login.
if (reseller_brand_for_host() !== null) redirect('/login');
// The "how it works" step and the FAQ describe how an account is actually obtained: with registration
// closed an admin creates accounts; otherwise the visitor signs up, verifies the mobile, and an admin
// approves. (The 'auto_after_otp' mode still lands in pending_admin_approval — see
// registration_verify_otp() — so it is described as approval too.)
$registrationOpen = registration_enabled();
$pageTitle = 'پنل هوشمند پیامک';
$extraStyles = ['/assets/css/landing-ai.css', '/assets/css/landing-products.css'];
$metaDescription = 'سامانه پیامکی و بازاریابی، خدمات ابری (VPS، هاست، دامنه و فضای ابری)، هوش مصنوعی و اتوماسیون، خدمات فناوری اطلاعات و تلفن سازمانی VoIP — ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی هوشمند با هوش مصنوعی، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
$packages = db()->query('SELECT * FROM ellsms_pricing_packages WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
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
          <a href="#features" class="btn btn-ghost">مشاهده‌ی امکانات</a>
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
          <a href="#ai-secretary" class="btn btn-primary">آشنایی با منشی هوشمند</a>
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
          <a href="#features" class="btn btn-ghost">مشاهده‌ی امکانات</a>
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
            <a href="#features" class="btn btn-ghost">امکانات پنل پیامک</a>
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

  <!-- How it works: one contacts file + one template -> a personal SMS per row, delivered.
       Plain HTML/CSS with a tiny script below (no library); it pauses off-screen and shows a
       still, fully-filled frame for visitors who prefer reduced motion. -->
  <section id="flow" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>از فایل مخاطبین تا گوشی مشتری</h2>
      <p>یک فایل و یک قالب کافی است؛ هر ردیف پیام مخصوص خودش را می‌گیرد و وضعیت تحویلش را همان لحظه می‌بینید.</p>
    </div>
    <div class="lp-flow" data-lp-flow aria-hidden="true">
      <div class="lp-flow-card lp-flow-sheet">
        <div class="lp-flow-card-title"><span class="lp-flow-file">XLSX</span>مخاطبین.xlsx</div>
        <div class="lp-flow-row lp-flow-row-head"><span>نام</span><span>موبایل</span><span>شانس</span></div>
        <div class="lp-flow-row is-active" data-row="0"><span>علی</span><span class="ltr">0912•••4471</span><span>۲</span></div>
        <div class="lp-flow-row" data-row="1"><span>مریم</span><span class="ltr">0935•••1187</span><span>۵</span></div>
        <div class="lp-flow-row" data-row="2"><span>رضا</span><span class="ltr">0919•••0032</span><span>۱</span></div>
      </div>
      <div class="lp-flow-link"><i></i><i></i><i></i></div>
      <div class="lp-flow-card lp-flow-template">
        <div class="lp-flow-card-title">قالب پیام</div>
        <p class="lp-flow-text">سلام <b class="lp-flow-token" data-token="name">{نام}</b>، شما <b class="lp-flow-token" data-token="chance">{شانس}</b> شانس در قرعه‌کشی دارید.</p>
      </div>
      <div class="lp-flow-link"><i></i><i></i><i></i></div>
      <div class="lp-flow-phone">
        <div class="lp-flow-phone-notch"></div>
        <div class="lp-flow-thread" data-thread>
          <div class="lp-flow-bubble is-in"><span>سلام علی، شما ۲ شانس در قرعه‌کشی دارید.</span><em class="is-done">تحویل شد ✓</em></div>
          <div class="lp-flow-bubble is-in"><span>سلام مریم، شما ۵ شانس در قرعه‌کشی دارید.</span><em class="is-done">تحویل شد ✓</em></div>
          <div class="lp-flow-bubble is-in"><span>سلام رضا، شما ۱ شانس در قرعه‌کشی دارید.</span><em class="is-done">تحویل شد ✓</em></div>
        </div>
      </div>
    </div>
  </section>
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
(function () {
  var root = document.querySelector('[data-lp-flow]');
  if (!root || !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var people = [['علی', '۲'], ['مریم', '۵'], ['رضا', '۱']];
  var rows = root.querySelectorAll('[data-row]');
  var tokens = { name: root.querySelector('[data-token="name"]'), chance: root.querySelector('[data-token="chance"]') };
  var thread = root.querySelector('[data-thread]');
  var timers = [], running = false, visible = false;
  function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
  function stop() { timers.forEach(clearTimeout); timers = []; running = false; }
  function step(i) {
    if (i === people.length) { later(function () { thread.innerHTML = ''; step(0); }, 2200); return; }
    var p = people[i];
    rows.forEach(function (r, k) { r.classList.toggle('is-active', k === i); });
    tokens.name.textContent = p[0]; tokens.chance.textContent = p[1];
    tokens.name.classList.add('is-filled'); tokens.chance.classList.add('is-filled');
    root.classList.add('is-sending');
    later(function () {
      var b = document.createElement('div');
      b.className = 'lp-flow-bubble';
      b.innerHTML = '<span></span><em>در حال ارسال…</em>';
      b.firstChild.textContent = 'سلام ' + p[0] + '، شما ' + p[1] + ' شانس در قرعه‌کشی دارید.';
      thread.appendChild(b);
      requestAnimationFrame(function () { b.classList.add('is-in'); });
      later(function () { b.lastChild.textContent = 'تحویل شد ✓'; b.lastChild.classList.add('is-done'); root.classList.remove('is-sending'); }, 1100);
    }, 900);
    later(function () { step(i + 1); }, 2600);
  }
  function start() { if (running || !visible || document.hidden) return; running = true; thread.innerHTML = ''; step(0); }
  new IntersectionObserver(function (entries) {
    visible = entries[0].isIntersecting;
    if (visible) start(); else stop();
  }, { threshold: 0.25 }).observe(root);
  document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
})();
</script>

  <section id="features" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>هر روش ارسالی که نیاز دارید</h2>
      <p>پنج حالت ارسال و یک منشی هوش مصنوعی در یک پنل. دسترسی به برخی امکانات ممکن است به پلن حساب بستگی داشته باشد.</p>
    </div>
    <div class="lp-bento">
      <article class="lp-card lp-bento-flag lp-reveal" style="--i:0">
        <span class="lp-icon lp-icon-light">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/></svg>
        </span>
        <h3>پیامک هوشمند با قالب پویا</h3>
        <p>یک قالب بنویسید، هزاران پیام شخصی‌سازی‌شده بفرستید — دقیقاً همان‌طور که در جدول‌تان نوشته‌اید.</p>
        <div class="lp-bento-chips">
          <span class="lp-chip">سلام {نام}</span>
          <span class="lp-chip">اعتبار شما {مبلغ} تومان</span>
          <span class="lp-chip">کد {کد}</span>
        </div>
      </article>
      <article class="lp-card lp-bento-wide lp-reveal" style="--i:1">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
        </span>
        <h3>گزارش و آمار تفصیلی</h3>
        <p>وضعیت هر پیامک، نمودار هفتگی ارسال، و آمار به تفکیک شماره، مشتری و اپراتور را لحظه‌ای ببینید.</p>
      </article>
      <article class="lp-card lp-bento-c1 lp-reveal" style="--i:2">
        <span class="lp-icon lp-icon-tint-b">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
        </span>
        <h3>منشی پیامک (کلیدواژه)</h3>
        <p>به پیامک‌های دریافتی بر اساس قوانین از‌پیش‌تعیین‌شده، بدون دخالت دستی، پاسخ خودکار بدهید.</p>
      </article>
      <article class="lp-card lp-bento-c2 lp-reveal" style="--i:3">
        <span class="lp-icon lp-icon-tint-a">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
        </span>
        <h3>ورود دومرحله‌ای</h3>
        <p>حساب‌های حساس را با کد پیامکی یک‌بارمصرف محافظت کنید — قابل‌فعال‌سازی برای یک کاربر یا کل مجموعه.</p>
      </article>
      <article class="lp-card lp-bento-c3 lp-reveal" style="--i:4">
        <span class="lp-icon lp-icon-tint-d">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/></svg>
        </span>
        <h3>ارسال مستقیم و دوره‌ای</h3>
        <p>پیامک را همین حالا بفرستید یا برای تاریخ و ساعت مشخص — با تقویم شمسی — زمان‌بندی کنید.</p>
      </article>
      <article class="lp-card lp-bento-c4 lp-reveal" style="--i:5">
        <span class="lp-icon lp-icon-tint-f">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        </span>
        <h3>ارسال تدریجی</h3>
        <p>ارسال را به‌صورت پلکانی و با فاصله‌ی زمانی کنترل‌شده انجام دهید، به‌جای ارسال همه در یک لحظه.</p>
      </article>
      <article class="lp-card lp-bento-c5 lp-reveal" style="--i:6">
        <span class="lp-icon lp-icon-tint-e">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
        </span>
        <h3>نظیر به نظیر</h3>
        <p>یک فایل اکسل یا CSV آپلود کنید و به هر مخاطب متنی کاملاً متفاوت، دقیقاً همان‌طور که نوشته‌اید، بفرستید.</p>
      </article>
      <article class="lp-card lp-bento-c6 lp-reveal" style="--i:7">
        <span class="lp-icon lp-icon-tint-c">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h3>مخاطبین و لیست سیاه</h3>
        <p>مخاطبین را گروه‌بندی کنید و با یک لیست سیاه، شماره‌های مسدود را پیش از هر ارسال به‌طور خودکار فیلتر کنید.</p>
      </article>
      <article class="lp-card lp-bento-ai lp-reveal" style="--i:8">
        <div class="lp-bento-ai-copy">
          <span class="lp-icon lp-icon-light">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 7V4M9 12h.01M15 12h.01M9.5 16h5"/></svg>
          </span>
          <h3>منشی هوشمند با هوش مصنوعی <span class="lp-new-badge">جدید</span></h3>
          <p>به پیامک‌های ورودی هر خط، از روی اطلاعات کسب‌وکار و جدول‌های خودتان (محصولات، سفارش‌ها، نوبت‌ها) خودکار جواب می‌دهد، گفتگو را هر وقت لازم شد به اپراتور می‌سپارد و در تلگرام هم پاسخ‌گوست.</p>
        </div>
        <div class="lp-bento-chips">
          <span class="lp-chip">پاسخ شبانه‌روزی</span>
          <span class="lp-chip">جواب از جدول‌ها</span>
          <span class="lp-chip">ارجاع به اپراتور</span>
          <span class="lp-chip">پیام اول</span>
        </div>
        <a href="#ai-secretary" class="btn lp-bento-ai-link">آشنایی کامل ←</a>
      </article>
    </div>
  </section>

  <!-- «منشی هوشمند» showcase: a seven-step slider that explains the AI SMS secretary
       (docs/ai-secretary.md, docs/landing.md). Each step pairs a CSS/SVG animated scene with real text.
       Tabs on the side are an ARIA tablist; auto-advance is driven by each tab's progress bar
       (animationend), pauses on hover / focus / off-screen / «توقف», and is off for reduced motion. -->
  <section id="ai-secretary" class="lp-section lp-ai lp-reveal">
    <div class="lp-section-head">
      <p class="lp-ai-eyebrow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/></svg>با هوش مصنوعی</p>
      <h2>منشی هوشمند؛ مشتری می‌پرسد، منشی جواب می‌دهد</h2>
      <p>یک منشی هوش مصنوعی روی هر خط، که از اطلاعات و جدول‌های خودتان به پیامک‌های ورودی جواب می‌دهد و هر جا لازم شد گفتگو را به شما می‌سپارد.</p>
    </div>

    <div class="lp-ai-tour" data-lp-ai aria-roledescription="carousel" aria-label="آشنایی با منشی هوشمند">
      <div class="lp-ai-tabs" role="tablist" aria-label="مراحل معرفی منشی هوشمند">
        <?php
          $aiSteps = [
            ['پاسخ خودکار، شبانه‌روزی', 'جواب در چند ثانیه، از همان خط'],
            ['راه‌اندازی در چند دقیقه', 'الگو، اطلاعات کسب‌وکار، روشن'],
            ['مسیر هر پیام', 'قانون‌های شما اول، بعد هوش مصنوعی'],
            ['جواب از جدول‌های شما', 'محصولات، سفارش‌ها، نوبت‌ها'],
            ['ارجاع به اپراتور', 'هر وقت لازم شد، انسان'],
            ['پیام اول با هوش مصنوعی', 'پیام شخصی برای هر ردیف'],
            ['امن، با هزینه‌ی شفاف', 'محافظ‌ها و هزینه‌ی هر پاسخ'],
          ];
          foreach ($aiSteps as $k => [$stepTitle, $stepSub]): $n = $k + 1;
        ?>
        <button type="button" role="tab" class="lp-ai-tab<?= $k === 0 ? ' is-active' : '' ?>" id="lpAiTab<?= $n ?>" aria-controls="lpAiStep<?= $n ?>" aria-selected="<?= $k === 0 ? 'true' : 'false' ?>"<?= $k === 0 ? '' : ' tabindex="-1"' ?>>
          <span class="lp-ai-tab-no"><?= to_persian_digits((string)$n) ?></span>
          <span class="lp-ai-tab-text"><b><?= e($stepTitle) ?></b><small><?= e($stepSub) ?></small></span>
          <span class="lp-ai-tab-bar" aria-hidden="true"><i></i></span>
        </button>
        <?php endforeach; ?>
      </div>

      <div class="lp-ai-panels">

        <!-- 1. Answering -->
        <article class="lp-ai-slide is-active" id="lpAiStep1" role="tabpanel" aria-labelledby="lpAiTab1" aria-roledescription="slide">
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۱ از ۷</span>
            <h3>پاسخ خودکار، شبانه‌روزی</h3>
            <p>مشتری به خط شما پیامک می‌دهد و منشی هوشمند با لحنی که خودتان تعیین کرده‌اید، از همان خط جواب می‌دهد؛ حتی نیمه‌شب و روزهای تعطیل.</p>
            <ul class="lp-ai-points">
              <li>یک منشی جدا برای هر خط</li>
              <li>چند پیام قبلی هر شماره را به خاطر می‌سپارد</li>
              <li>طول پاسخ به تعداد پارتی که تعیین می‌کنید محدود است</li>
              <li>اکانت تلگرامتان را وصل کنید، در تلگرام هم جواب می‌دهد</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-phone">
              <div class="lp-ai-phone-head"><span class="lp-ai-avatar">م</span><div><b>مشتری</b><span class="ltr">0912 *** 4471</span></div></div>
              <div class="lp-ai-phone-body">
                <p class="lp-ai-msg is-in lp-ai-pop" style="--t:.3s">سلام، فردا عصر نوبت خالی دارید؟<small>۲۲:۱۵</small></p>
                <p class="lp-ai-typing" style="--t:1s"><i></i><i></i><i></i></p>
                <p class="lp-ai-msg is-out lp-ai-pop" style="--t:2.5s">سلام! بله، فردا ساعت <b>۱۸:۳۰</b> وقت خالی داریم. برای رزرو عدد <b>۱</b> را بفرستید.<small>۲۲:۱۵ · منشی</small></p>
                <p class="lp-ai-msg is-in lp-ai-pop" style="--t:3.6s">۱<small>۲۲:۱۶</small></p>
                <p class="lp-ai-msg is-out lp-ai-pop" style="--t:4.5s">نوبت شما برای فردا ۱۸:۳۰ ثبت شد ✅<small>۲۲:۱۶ · منشی</small></p>
              </div>
            </div>
            <div class="lp-ai-badge is-tl lp-ai-pop" style="--t:1.6s"><b>۲۴/۷</b>پاسخ‌گو</div>
            <div class="lp-ai-badge is-br lp-ai-pop" style="--t:2s"><span class="lp-ai-ch is-sms">پیامک</span><span class="lp-ai-ch is-tg">تلگرام</span></div>
          </div>
        </article>

        <!-- 2. Setup -->
        <article class="lp-ai-slide" id="lpAiStep2" role="tabpanel" aria-labelledby="lpAiTab2" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۲ از ۷</span>
            <h3>راه‌اندازی در چند دقیقه</h3>
            <p>برای هر خط «راه‌اندازی» را بزنید، یک الگو انتخاب کنید، اطلاعات کسب‌وکارتان را بنویسید و منشی را روشن کنید.</p>
            <ul class="lp-ai-points">
              <li>الگوهای آماده: فروشگاه، کلینیک، خدمات و دستیار عمومی</li>
              <li>«منشی کسب‌وکار» فقط از اطلاعات شما جواب می‌دهد و قیمت یا قولی از خودش نمی‌سازد</li>
              <li>«دستیار عمومی» به هر سؤالی جواب می‌دهد</li>
              <li>با «آزمایش منشی» جواب را پیش از روشن‌کردن ببینید</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-form">
              <div class="lp-ai-form-title">تنظیمات منشی · خط <span class="ltr">30001234</span></div>
              <div class="lp-ai-form-label">الگو</div>
              <div class="lp-ai-tpls">
                <span>فروشگاه</span><span class="is-pick">کلینیک</span><span>خدمات</span><span>دستیار عمومی</span>
              </div>
              <div class="lp-ai-form-label">حالت</div>
              <div class="lp-ai-seg"><span class="is-on">منشی کسب‌وکار</span><span>دستیار عمومی</span></div>
              <div class="lp-ai-form-label">اطلاعات کسب‌وکار</div>
              <div class="lp-ai-info">
                <p class="lp-ai-line" style="--t:.9s">ساعت کاری: شنبه تا پنجشنبه، ۹ تا ۲۰</p>
                <p class="lp-ai-line" style="--t:1.5s">ویزیت عمومی: ۴۵۰ هزار تومان</p>
                <p class="lp-ai-line" style="--t:2.1s">آدرس: خیابان ولیعصر، کوچه‌ی ۱۲</p>
                <p class="lp-ai-line" style="--t:2.7s">پارکینگ: دارد · بیمه: تکمیلی</p>
              </div>
              <div class="lp-ai-form-foot">
                <span class="lp-ai-switch"><i></i></span><b>منشی هوشمند روشن باشد</b>
                <span class="lp-ai-save lp-ai-pop" style="--t:4s">ذخیره شد ✓</span>
              </div>
            </div>
          </div>
        </article>

        <!-- 3. Flow -->
        <article class="lp-ai-slide" id="lpAiStep3" role="tabpanel" aria-labelledby="lpAiTab3" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۳ از ۷</span>
            <h3>مسیر هر پیام، شفاف و قابل پیش‌بینی</h3>
            <p>منشی هوشمند جای قانون‌های شما را نمی‌گیرد؛ بعد از آن‌ها وارد کار می‌شود و فقط به پیام‌هایی جواب می‌دهد که جواب دیگری نگرفته‌اند.</p>
            <ul class="lp-ai-points">
              <li>«۱۱» و «۱۲» همیشه لغو و عضویت‌اند و به هوش مصنوعی نمی‌رسند</li>
              <li>اگر یکی از قانون‌های «منشی پیامک» جواب داد، منشی هوشمند کنار می‌ماند</li>
              <li>پاسخ از همان خطی می‌رود که پیام به آن رسیده</li>
              <li>همه‌ی گفتگوها و دلیل هر پاسخ‌ندادن در بخش «گفتگوها»</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <ol class="lp-ai-flow">
              <li style="--t:.2s"><span class="lp-ai-node"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span><b>پیامک ورودی</b><small>«سلام، قیمت ویزیت چنده؟»</small></li>
              <li style="--t:1s"><span class="lp-ai-node is-muted"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/></svg></span><b>لغو عضویت «۱۱/۱۲»</b><small>نیست، ادامه</small></li>
              <li style="--t:1.8s"><span class="lp-ai-node is-muted"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h10M4 17h7"/></svg></span><b>قانون‌های کلیدواژه</b><small>قانونی جواب نداد، ادامه</small></li>
              <li style="--t:2.6s" class="is-ai"><span class="lp-ai-node is-ai"><svg viewBox="0 0 24 24"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/></svg></span><b>منشی هوشمند</b><small>اطلاعات کسب‌وکار + حافظه‌ی گفتگو</small></li>
              <li style="--t:3.4s"><span class="lp-ai-node is-ok"><svg viewBox="0 0 24 24"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/></svg></span><b>پاسخ از همان خط</b><small>«ویزیت عمومی ۴۵۰ هزار تومان است.»</small></li>
            </ol>
          </div>
        </article>

        <!-- 4. Data tables -->
        <article class="lp-ai-slide" id="lpAiStep4" role="tabpanel" aria-labelledby="lpAiTab4" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۴ از ۷</span>
            <h3>جواب از جدول‌های خودتان</h3>
            <p>محصولات، سفارش‌ها یا نوبت‌ها را در «مدیریت جدول‌ها» بسازید یا از اکسل وارد کنید؛ منشی به سؤالی مثل «سفارش ۴۵۱۲ کجاست؟» از همان جدول جواب می‌دهد.</p>
            <ul class="lp-ai-points">
              <li>ساخت جدول از الگو، فایل اکسل/CSV یا به‌صورت دستی</li>
              <li>با «ستون موبایل»، هر مشتری فقط ردیف‌های شماره‌ی خودش را می‌بیند</li>
              <li>متن‌های طولانی مثل شرایط گارانتی را به‌صورت سند (txt، docx، pdf) بدهید</li>
              <li>منشی فقط بخش‌های مرتبط با سؤال را می‌خواند</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-lookup">
              <p class="lp-ai-msg is-in lp-ai-pop" style="--t:.3s">سفارش ۴۵۱۲ کجاست؟</p>
              <div class="lp-ai-table lp-ai-pop" style="--t:.9s">
                <div class="lp-ai-table-title"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/></svg>جدول «سفارش‌ها»<span class="lp-ai-lock"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>فقط شماره‌ی خود مشتری</span></div>
                <div class="lp-ai-tr is-head"><span>کد</span><span>نام</span><span>وضعیت</span><span>رهگیری</span></div>
                <div class="lp-ai-tr is-dim"><span>۴۵۰۹</span><span>•••</span><span>•••</span><span>•••</span></div>
                <div class="lp-ai-tr is-hit"><span>۴۵۱۲</span><span>سارا</span><span>ارسال شد</span><span class="ltr">RT998877</span></div>
                <div class="lp-ai-tr is-dim"><span>۴۵۱۷</span><span>•••</span><span>•••</span><span>•••</span></div>
                <span class="lp-ai-scan"></span>
              </div>
              <p class="lp-ai-msg is-out lp-ai-pop" style="--t:3s">سارا خانم سلام، سفارش ۴۵۱۲ امروز ارسال شد. کد رهگیری: <b class="ltr">RT998877</b></p>
            </div>
          </div>
        </article>

        <!-- 5. Hand-off -->
        <article class="lp-ai-slide" id="lpAiStep5" role="tabpanel" aria-labelledby="lpAiTab5" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۵ از ۷</span>
            <h3>هر وقت لازم شد، اپراتور</h3>
            <p>وقتی مشتری اپراتور بخواهد، شکایت کند یا منشی نتواند کمک کند، گفتگو به شما سپرده می‌شود و منشی کنار می‌رود.</p>
            <ul class="lp-ai-points">
              <li>اعلان در پنل برای گفتگوی ارجاع‌شده</li>
              <li>منشی تا مدتی که تعیین کرده‌اید به آن شماره جواب نمی‌دهد</li>
              <li>پاسخ دستی در «گفتگوهای ارجاع‌شده به اپراتور»</li>
              <li>با «ادامه با منشی هوشمند» گفتگو را به منشی برگردانید</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-handoff">
              <p class="lp-ai-msg is-in lp-ai-pop" style="--t:.3s">می‌خوام با یه نفر صحبت کنم، از ارسال ناراضی‌ام.</p>
              <p class="lp-ai-msg is-out lp-ai-pop" style="--t:1.3s">حتماً؛ گفتگو را به همکارم سپردم و به‌زودی پاسخ می‌دهد.<small>منشی</small></p>
              <div class="lp-ai-toast lp-ai-pop" style="--t:2.2s">
                <span class="lp-ai-bell"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
                <div><b>گفتگو به اپراتور ارجاع شد</b><span>منشی برای این شماره متوقف است</span></div>
              </div>
              <p class="lp-ai-msg is-out is-human lp-ai-pop" style="--t:3.3s">سلام، من مریم از پشتیبانی هستم؛ سفارش شما را پیگیری کردم…<small>اپراتور</small></p>
              <span class="lp-ai-resume lp-ai-pop" style="--t:4.3s"><svg viewBox="0 0 24 24"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/></svg>ادامه با منشی هوشمند</span>
            </div>
          </div>
        </article>

        <!-- 6. First message (outreach) -->
        <article class="lp-ai-slide" id="lpAiStep6" role="tabpanel" aria-labelledby="lpAiTab6" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۶ از ۷</span>
            <h3>پیام اول را هم منشی می‌نویسد</h3>
            <p>در بخش «پیام اول»، منشی برای هر ردیف یک جدول (مثلاً سفارش‌های ارسال‌شده) یک پیام شخصی می‌نویسد و از همان خط می‌فرستد.</p>
            <ul class="lp-ai-points">
              <li>پیش‌نمایش دو پیام اول، پیش از شروع</li>
              <li>ارسال با پیامک، بله، یا بله و سپس پیامک</li>
              <li>پاسخ مشتری مستقیم به منشی می‌رسد</li>
              <li>لغو در هر لحظه؛ پیام‌های ارسال‌نشده متوقف می‌شوند</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-outreach">
              <div class="lp-ai-rows">
                <div class="lp-ai-row lp-ai-pop" style="--t:.2s"><b>سارا</b><span>۴۵۱۲</span><span class="ltr">RT998877</span></div>
                <div class="lp-ai-row lp-ai-pop" style="--t:.4s"><b>امیر</b><span>۴۵۱۴</span><span class="ltr">RT998912</span></div>
                <div class="lp-ai-row lp-ai-pop" style="--t:.6s"><b>نیلوفر</b><span>۴۵۲۰</span><span class="ltr">RT999031</span></div>
              </div>
              <div class="lp-ai-prompt lp-ai-pop" style="--t:1s"><svg viewBox="0 0 24 24"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/></svg>«به مشتری خبر بده سفارشش ارسال شد و کد رهگیری را بده»</div>
              <div class="lp-ai-outs">
                <p class="lp-ai-out lp-ai-pop" style="--t:2s"><span class="lp-ai-ch is-sms">پیامک</span>سارا خانم سلام، سفارش ۴۵۱۲ امروز ارسال شد. کد رهگیری: RT998877</p>
                <p class="lp-ai-out lp-ai-pop" style="--t:2.8s"><span class="lp-ai-ch is-bale">بله</span>آقای امیر، بسته‌ی سفارش ۴۵۱۴ در راه است؛ کد رهگیری RT998912</p>
                <p class="lp-ai-out lp-ai-pop" style="--t:3.6s"><span class="lp-ai-ch is-sms">پیامک</span>نیلوفر جان، سفارش ۴۵۲۰ ارسال شد. رهگیری: RT999031</p>
              </div>
            </div>
          </div>
        </article>

        <!-- 7. Guards and cost -->
        <article class="lp-ai-slide" id="lpAiStep7" role="tabpanel" aria-labelledby="lpAiTab7" aria-roledescription="slide" hidden>
          <div class="lp-ai-text">
            <span class="lp-ai-step">مرحله‌ی ۷ از ۷</span>
            <h3>امن، کنترل‌شده، با هزینه‌ی شفاف</h3>
            <p>منشی به سرشماره‌ها و ربات‌ها جواب نمی‌دهد و دستورهایی را که داخل پیام مشتری نوشته شده اجرا نمی‌کند.</p>
            <ul class="lp-ai-points">
              <li>جلوگیری از حلقه‌ی ربات‌ها، پیام تکراری و پیام‌های خیلی قدیمی</li>
              <li>سقف پاسخ به هر شماره در ساعت و سقف روزانه‌ی خط</li>
              <li>هزینه‌ی هر پاسخ: پیامکِ پاسخ + هزینه‌ی هوش مصنوعی</li>
              <li>هزینه‌ی هوش مصنوعی فقط پس از ارسال موفق پاسخ کسر می‌شود</li>
            </ul>
          </div>
          <div class="lp-ai-stage" aria-hidden="true">
            <div class="lp-ai-guard">
              <div class="lp-ai-shield"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></div>
              <ul class="lp-ai-checks">
                <li class="lp-ai-pop" style="--t:.4s"><i>✓</i>سرشماره و ربات: بدون پاسخ</li>
                <li class="lp-ai-pop" style="--t:.8s"><i>✓</i>ضد حلقه و پیام تکراری</li>
                <li class="lp-ai-pop" style="--t:1.2s"><i>✓</i>سقف ساعتی و روزانه</li>
                <li class="lp-ai-pop" style="--t:1.6s"><i>✓</i>لغو عضویت‌شده‌ها: بدون پاسخ</li>
              </ul>
              <div class="lp-ai-receipt lp-ai-pop" style="--t:2.2s">
                <div class="lp-ai-receipt-title">هزینه‌ی یک پاسخ</div>
                <div class="lp-ai-receipt-row"><span>پیامکِ پاسخ</span><b>مثل پیامک عادی</b></div>
                <div class="lp-ai-receipt-row"><span>هوش مصنوعی</span><b>طبق تعرفه</b></div>
                <div class="lp-ai-receipt-row is-sum"><span>کسر از اعتبار</span><b>پس از ارسال موفق</b></div>
              </div>
            </div>
          </div>
        </article>

      </div>

      <div class="lp-ai-controls">
        <button type="button" class="lp-ai-arrow" data-lp-ai-prev aria-label="مرحله‌ی قبل"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
        <span class="lp-ai-count" aria-live="polite"><b data-lp-ai-count>۱</b> / ۷</span>
        <button type="button" class="lp-ai-arrow" data-lp-ai-next aria-label="مرحله‌ی بعد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button>
        <button type="button" class="lp-banner-toggle lp-ai-toggle" data-lp-ai-toggle aria-pressed="false">
          <span class="lp-banner-toggle-icon" aria-hidden="true"></span><span data-lp-ai-toggle-label>توقف</span>
        </button>
      </div>
    </div>

    <div class="lp-ai-cta">
      <p>منشی هوشمند برای حساب‌هایی فعال می‌شود که سرویس هوش مصنوعی برایشان روشن شده و خط ارسال دارند. برای فعال‌سازی با پشتیبانی تماس بگیرید.</p>
      <div>
        <a href="/contact.php" class="btn btn-primary">درخواست فعال‌سازی</a>
        <a href="/guide.php#guide-ai-secretary" class="btn btn-ghost">راهنمای منشی هوشمند</a>
      </div>
    </div>
  </section>
<script>
(function () {
  var root = document.querySelector('[data-lp-ai]');
  if (!root) return;
  var tabs = root.querySelectorAll('.lp-ai-tab');
  var slides = root.querySelectorAll('.lp-ai-slide');
  var count = root.querySelector('[data-lp-ai-count]');
  var toggle = root.querySelector('[data-lp-ai-toggle]');
  var toggleLabel = root.querySelector('[data-lp-ai-toggle-label]');
  var fa = '۰۱۲۳۴۵۶۷۸۹';
  var current = 0, hovering = false, focused = false, visible = false;
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var stopped = still;
  function show(i, moveFocus) {
    current = (i + slides.length) % slides.length;
    slides.forEach(function (s, k) { var on = k === current; s.classList.toggle('is-active', on); s.hidden = !on; });
    tabs.forEach(function (t, k) {
      var on = k === current;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      t.classList.remove('is-done');
      if (k < current) t.classList.add('is-done');
    });
    count.textContent = fa[current + 1];
    // Keep the active tab in view when the tabs scroll sideways (narrow screens), without moving the page.
    var t = tabs[current], strip = t.parentNode;
    if (strip.scrollWidth > strip.clientWidth) {
      var tr = t.getBoundingClientRect(), sr = strip.getBoundingClientRect();
      strip.scrollBy({ left: (tr.left + tr.width / 2) - (sr.left + sr.width / 2), behavior: still ? 'auto' : 'smooth' });
    }
    if (moveFocus) t.focus();
  }
  function sync() {
    // The tab's progress bar is the timer: its animation runs only while auto-advance is allowed.
    var hold = stopped || hovering || focused || !visible || document.hidden;
    root.classList.toggle('is-holding', hold);
  }
  function replay() {
    // Coming back into view: play the current scene again from its start.
    var s = slides[current];
    s.classList.remove('is-active'); void s.offsetWidth; s.classList.add('is-active');
  }
  tabs.forEach(function (t, k) {
    t.querySelector('.lp-ai-tab-bar i').addEventListener('animationend', function () { if (k === current) show(current + 1); });
    t.addEventListener('click', function () { show(k); });
    t.addEventListener('keydown', function (e) {
      var vertical = getComputedStyle(t.parentNode).flexDirection === 'column';
      var step = vertical ? { ArrowDown: 1, ArrowUp: -1 }[e.key] : { ArrowLeft: 1, ArrowRight: -1 }[e.key]; // RTL
      if (step) { e.preventDefault(); show(current + step, true); }
      else if (e.key === 'Home') { e.preventDefault(); show(0, true); }
      else if (e.key === 'End') { e.preventDefault(); show(slides.length - 1, true); }
    });
  });
  root.querySelector('[data-lp-ai-prev]').addEventListener('click', function () { show(current - 1); });
  root.querySelector('[data-lp-ai-next]').addEventListener('click', function () { show(current + 1); });
  toggle.addEventListener('click', function () {
    stopped = !stopped;
    toggle.setAttribute('aria-pressed', stopped ? 'true' : 'false');
    toggleLabel.textContent = stopped ? 'پخش خودکار' : 'توقف';
    sync();
  });
  root.addEventListener('mouseenter', function () { hovering = true; sync(); });
  root.addEventListener('mouseleave', function () { hovering = false; sync(); });
  root.addEventListener('focusin', function () { focused = true; sync(); });
  root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) { focused = false; sync(); } });
  var x0 = null;
  var panels = root.querySelector('.lp-ai-panels');
  panels.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  panels.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 40) show(current + (dx > 0 ? 1 : -1)); // RTL: swipe right = next
  });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      var en = entries[0];
      // "In view" = a good part of the tour is on screen (it is taller than a phone screen).
      var now = en.isIntersecting && en.intersectionRect.height >= Math.min(320, en.boundingClientRect.height * 0.3);
      if (now && !visible) replay();
      visible = now; sync();
    }, { threshold: [0, 0.1, 0.2, 0.3, 0.5] }).observe(root);
  } else { visible = true; }
  document.addEventListener('visibilitychange', sync);
  if (still) { toggle.setAttribute('aria-pressed', 'true'); toggleLabel.textContent = 'پخش خودکار'; }
  sync();
})();
</script>

  <section id="compare" class="lp-section lp-compare lp-reveal">
    <div class="lp-section-head">
      <h2>چرا به‌جای ارسال دستی؟</h2>
      <p>همان کار را می‌شد با اکسل و گوشی هم انجام داد — فقط ساعت‌ها زمان و کنترل کمتری روی نتیجه.</p>
    </div>
    <div class="lp-compare-table">
      <div class="lp-compare-row lp-compare-head">
        <div></div>
        <div>ارسال دستی / اکسل پراکنده</div>
        <div class="lp-compare-highlight">داتیس رایان گستر ایرانیان</div>
      </div>
      <div class="lp-compare-row">
        <div>شخصی‌سازی هر پیام</div>
        <div class="lp-compare-no">کپی‌پیست تکی، وقت‌گیر</div>
        <div class="lp-compare-yes">یک قالب، هزاران پیام متفاوت</div>
      </div>
      <div class="lp-compare-row">
        <div>زمان‌بندی ارسال</div>
        <div class="lp-compare-no">نیاز به حضور پای گوشی</div>
        <div class="lp-compare-yes">تاریخ و ساعت را تعیین کنید، بقیه‌اش با پنل</div>
      </div>
      <div class="lp-compare-row">
        <div>پاسخ به پیام‌های دریافتی</div>
        <div class="lp-compare-no">دستی و با تأخیر</div>
        <div class="lp-compare-yes">منشی هوشمند و منشی پیامک، پاسخ خودکار و شبانه‌روزی</div>
      </div>
      <div class="lp-compare-row">
        <div>گزارش وضعیت تحویل</div>
        <div class="lp-compare-no">مشخص نیست کدام پیام رسیده</div>
        <div class="lp-compare-yes">وضعیت لحظه‌ای هر پیامک</div>
      </div>
      <div class="lp-compare-row">
        <div>فیلتر شماره‌های مسدود</div>
        <div class="lp-compare-no">به عهده‌ی خود فرستنده</div>
        <div class="lp-compare-yes">لیست سیاه، خودکار پیش از ارسال</div>
      </div>
    </div>
  </section>

  <?php if ($packages): ?>
  <section id="pricing" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>بسته‌های پیامک</h2>
      <p>بسته‌ای متناسب با حجم ارسال خود انتخاب کنید.</p>
    </div>
    <div class="lp-pricing-grid">
      <?php foreach ($packages as $i => $p): ?>
        <div class="lp-price-card lp-reveal<?= $p['is_featured'] ? ' is-featured' : '' ?>" style="--i:<?= (int)$i ?>">
          <?php if ($p['is_featured']): ?><span class="lp-price-badge">پیشنهاد ویژه</span><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <div class="lp-price-amount"><?= to_persian_digits(number_format((int)$p['price_rial'])) ?> <span>ریال</span></div>
          <div class="lp-price-credit"><?= to_persian_digits(number_format((int)$p['credit_amount'])) ?> ریال اعتبار</div>
          <?php
            $features = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$p['features'])));
          ?>
          <?php if ($features): ?>
            <ul class="lp-price-features">
              <?php foreach ($features as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <a href="<?= e($primaryHref) ?>" class="btn btn-primary btn-block">شروع کنید</a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section id="how" class="lp-section lp-how lp-reveal">
    <div class="lp-section-head">
      <h2>سه قدم تا اولین ارسال</h2>
      <p>همه‌چیز در مرورگر انجام می‌شود؛ نیازی به نصب برنامه نیست.</p>
    </div>
    <ol class="lp-steps">
      <li>
        <span class="lp-step-no">۱</span>
        <?php if (!$registrationOpen): ?>
        <h3>دریافت دسترسی</h3>
        <p>ثبت‌نام عمومی فعلاً بسته است؛ حساب کاربری را مدیر سامانه می‌سازد. برای دریافت حساب با ما <a href="/contact">تماس بگیرید</a>.</p>
        <?php else: ?>
        <h3>ثبت‌نام</h3>
        <p><a href="/register">ثبت‌نام کنید</a> و شماره موبایل خود را با کد پیامکی تأیید کنید؛ حساب پس از بررسی و تأیید مدیر فعال می‌شود.</p>
        <?php endif; ?>
      </li>
      <li>
        <span class="lp-step-no">۲</span>
        <h3>شارژ اعتبار</h3>
        <p>از طریق درگاه زرین‌پال اعتبار بخرید؛ پس از تأیید پرداخت، اعتبار در پنل قابل استفاده است.</p>
      </li>
      <li>
        <span class="lp-step-no">۳</span>
        <h3>ارسال و پیگیری</h3>
        <p>از میان چند حالت ارسال انتخاب کنید و وضعیت تحویل هر پیام را به‌صورت زنده دنبال کنید.</p>
      </li>
    </ol>
  </section>

  <section id="use-cases" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>برای چه کسب‌وکارهایی مناسب است؟</h2>
      <p>هر جا لازم باشد پیام درست، به آدم درست، سر وقت برسد.</p>
    </div>
    <div class="lp-grid">
      <article class="lp-usecase lp-reveal" style="--i:0">
        <span class="lp-icon lp-icon-tint-a">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.6 13.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 2-1.6L23 6H6"/></svg>
        </span>
        <h3>فروشگاه اینترنتی</h3>
        <p>کد تخفیف، پیگیری سفارش و یادآوری سبد خرید رهاشده — با قالب پویا و به نام هر مشتری.</p>
      </article>
      <article class="lp-usecase lp-reveal" style="--i:1">
        <span class="lp-icon lp-icon-tint-b">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="M12 8v4l3 2"/></svg>
        </span>
        <h3>کلینیک و مطب</h3>
        <p>یادآوری خودکار نوبت، پیش از ساعت مراجعه — بدون تماس تلفنی و بدون فراموشی.</p>
      </article>
      <article class="lp-usecase lp-reveal" style="--i:2">
        <span class="lp-icon lp-icon-tint-c">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h3>باشگاه مشتریان</h3>
        <p>اطلاع‌رسانی تخفیف‌های ویژه و امتیاز وفاداری به گروه‌های مشخصی از مخاطبین.</p>
      </article>
      <article class="lp-usecase lp-reveal" style="--i:3">
        <span class="lp-icon lp-icon-tint-d">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
        </span>
        <h3>شرکت‌های خدماتی</h3>
        <p>کد یک‌بارمصرف برای ورود امن، و اعلان وضعیت سفارش یا قرارداد به مشتریان.</p>
      </article>
    </div>
  </section>

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
        <div class="lp-guide-body">یک منشی هوش مصنوعی روی خط شماست که از اطلاعات کسب‌وکار و جدول‌هایی که خودتان می‌دهید به پیامک‌های ورودی جواب می‌دهد و در صورت نیاز گفتگو را به اپراتور می‌سپارد. هر پاسخ دو بخش هزینه دارد: پیامکِ پاسخ (مثل پیامک عادی) و هزینه‌ی هوش مصنوعی طبق تعرفه، که فقط پس از ارسال موفق پاسخ کسر می‌شود. برای فعال‌سازی با <a href="/contact">پشتیبانی</a> تماس بگیرید. <a href="#ai-secretary">معرفی کامل منشی هوشمند</a></div>
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
