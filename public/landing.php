<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
// The "how it works" step and the FAQ describe how an account is actually obtained: with registration
// closed an admin creates accounts; otherwise the visitor signs up, verifies the mobile, and an admin
// approves. (The 'auto_after_otp' mode still lands in pending_admin_approval — see
// registration_verify_otp() — so it is described as approval too.)
$registrationOpen = registration_enabled();
$pageTitle = 'پنل هوشمند پیامک';
$metaDescription = 'ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
$packages = db()->query('SELECT * FROM ellsms_pricing_packages WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/public_header.php';
?>
  <!-- Top banner: five animated slides (plain HTML/CSS + a tiny script below, no video file).
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

      <article class="lp-banner-slide is-active" id="lpSlide1" role="tabpanel" aria-roledescription="slide" aria-label="۱ از ۵">
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

      <article class="lp-banner-slide" id="lpSlide2" role="tabpanel" aria-roledescription="slide" aria-label="۲ از ۵">
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

      <article class="lp-banner-slide" id="lpSlide3" role="tabpanel" aria-roledescription="slide" aria-label="۳ از ۵">
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

      <article class="lp-banner-slide" id="lpSlide4" role="tabpanel" aria-roledescription="slide" aria-label="۴ از ۵">
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

      <article class="lp-banner-slide" id="lpSlide5" role="tabpanel" aria-roledescription="slide" aria-label="۵ از ۵">
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
          از ارسال ساده و زمان‌بندی‌شده تا پیامک هوشمند با قالب پویا، منشی پیامک خودکار
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
      <p>پنج حالت ارسال در یک پنل. دسترسی به برخی امکانات ممکن است به پلن حساب بستگی داشته باشد.</p>
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
        <h3>منشی پیامک</h3>
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
    </div>
  </section>

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
        <div class="lp-compare-yes">منشی پیامک، پاسخ خودکار طبق قوانین شما</div>
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
