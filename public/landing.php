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
$extraStyles = ['/assets/css/landing-ai.css'];
$metaDescription = 'ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی هوشمند با هوش مصنوعی، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
$packages = db()->query('SELECT * FROM ellsms_pricing_packages WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
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
  <!-- Top banner: six animated slides (plain HTML/CSS + a tiny script below, no video file).
       Slide 1 reuses the tilted-phone shot of the ELLSMS promo video as one still (≈43 KB WebP);
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

      <article class="lp-banner-slide is-active" id="lpSlide1" role="tabpanel" aria-roledescription="slide" aria-label="۱ از ۶">
        <div class="lp-banner-visual lp-banner-phone" aria-hidden="true">
          <picture>
            <source srcset="/assets/img/landing/banner-phone.webp" type="image/webp">
            <img src="/assets/img/landing/banner-phone.jpg" alt="" width="640" height="720" decoding="async" fetchpriority="high">
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

      <article class="lp-banner-slide" id="lpSlide2" role="tabpanel" aria-roledescription="slide" aria-label="۲ از ۶">
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

      <article class="lp-banner-slide" id="lpSlide3" role="tabpanel" aria-roledescription="slide" aria-label="۳ از ۶">
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

      <article class="lp-banner-slide" id="lpSlide4" role="tabpanel" aria-roledescription="slide" aria-label="۴ از ۶">
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

      <article class="lp-banner-slide" id="lpSlide5" role="tabpanel" aria-roledescription="slide" aria-label="۵ از ۶">
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

      <article class="lp-banner-slide" id="lpSlide6" role="tabpanel" aria-roledescription="slide" aria-label="۶ از ۶">
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
    timer = setTimeout(function () { show(current + 1); schedule(); }, 7000);
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

  <section id="customers" class="lp-section lp-customers lp-reveal">
    <div class="lp-section-head">
      <h2>مشتریان و همکاران</h2>
    </div>
    <?php
    // Two rails moving in opposite directions. Each rail holds the list twice so translating it by
    // -50% loops seamlessly; only the first copy of the first rail is exposed to screen readers.
    $customerRails = [$customers, array_reverse($customers)];
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
    <div class="lp-marquee">
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
