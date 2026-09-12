<?php
require_once __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'پنل هوشمند پیامک';
$metaDescription = 'ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
$videos   = db()->query('SELECT * FROM ellsms_landing_videos WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$packages = db()->query('SELECT * FROM ellsms_pricing_packages WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/public_header.php';
?>
  <?php if ($videos): ?>
    <section class="lp-slider-full" id="lpVideoSlider">
      <div class="lp-slider-viewport">
        <?php foreach ($videos as $i => $v):
          $posterPath = $v['poster'] ? '/assets/img/landing-video-posters/' . $v['poster'] : '';
        ?>
          <div class="lp-slide<?= $i === 0 ? ' is-active' : '' ?>" data-fallback="<?= e($posterPath) ?>">
            <video <?= $i === 0 ? 'autoplay ' : '' ?>muted loop playsinline preload="metadata"
              <?php if ($posterPath): ?>poster="<?= e($posterPath) ?>"<?php endif; ?>>
              <source src="/assets/video/landing/<?= e($v['video']) ?>">
            </video>
            <?php if ($posterPath): ?>
              <img src="<?= e($posterPath) ?>" alt="" class="lp-video-hover-image">
            <?php endif; ?>
            <button type="button" class="lp-video-mute" aria-label="پخش صدا / بی‌صدا کردن ویدیو" aria-pressed="false">🔇</button>
            <?php if ($v['title'] || $v['body'] || $v['link_url']): ?>
              <div class="lp-slide-caption">
                <div class="lp-slide-caption-inner">
                  <?php if ($v['title']): ?><h3><?= e($v['title']) ?></h3><?php endif; ?>
                  <?php if ($v['body']): ?><p><?= e($v['body']) ?></p><?php endif; ?>
                  <?php if ($v['link_url']): ?><a href="<?= e($v['link_url']) ?>" class="btn btn-primary btn-sm">مشاهده</a><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($videos) > 1): ?>
        <button type="button" class="lp-slider-nav lp-slider-prev" aria-label="ویدیوی قبلی">‹</button>
        <button type="button" class="lp-slider-nav lp-slider-next" aria-label="ویدیوی بعدی">›</button>
        <div class="lp-slider-dots">
          <?php foreach ($videos as $i => $v): ?>
            <button type="button" class="lp-dot<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= $i ?>" aria-label="ویدیوی <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="lp-scroll-progress" aria-hidden="true"><div class="lp-scroll-progress-bar" id="lpScrollBar"></div></div>

  <section class="lp-hero">
    <div class="lp-hero-inner">
      <div class="lp-hero-copy">
        <p class="lp-eyebrow">پنل هوشمند پیامک</p>
        <h1>ارسال پیامک انبوه، شخصی‌سازی‌شده و خودکار<br>همه در یک پنل</h1>
        <p class="lp-hero-sub">
          از ارسال ساده و زمان‌بندی‌شده تا پیامک هوشمند با قالب پویا، منشی پیامک خودکار
          و گزارش لحظه‌ای وضعیت هر پیام — ELLSMS ابزار پیامک‌رسانی کسب‌وکار شماست.
        </p>
        <div class="lp-hero-cta">
          <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
          <a href="#features" class="btn btn-ghost">مشاهده‌ی امکانات</a>
        </div>
        <ul class="lp-hero-stats">
          <li><strong>۵</strong><span>حالت ارسال</span></li>
          <li><strong>نامحدود</strong><span>قالب پویا</span></li>
          <li><strong>۲۴/۷</strong><span>پردازش پس‌زمینه</span></li>
          <li><strong>زرین‌پال</strong><span>پرداخت امن</span></li>
        </ul>
      </div>
      <div class="lp-hero-visual" aria-hidden="true">
        <div class="lp-phone">
          <div class="lp-phone-notch"></div>
          <div class="lp-phone-screen">
            <div class="lp-thread-head">
              <span class="lp-thread-avatar">ب</span>
              <div>
                <div class="lp-thread-name">باشگاه مشتریان</div>
                <div class="lp-thread-sub">پیامک تبلیغاتی</div>
              </div>
            </div>
            <div class="lp-bubble lp-bubble-in" style="--d:.1s">کد تخفیف شما آماده شد 🎉</div>
            <div class="lp-bubble lp-bubble-out" style="--d:.9s">
              سارا عزیز، ۲۰٪ تخفیف ویژه‌ی شما تا امشب فعال است.
              <span class="lp-bubble-tick">
                <svg viewBox="0 0 16 16" fill="none"><path d="M1 8.5 4.5 12 9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.2 8.5 9.7 12 15 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
            </div>
            <div class="lp-bubble lp-bubble-status" style="--d:1.7s">تحویل شد · همین الان</div>
            <div class="lp-typing" style="--d:2.3s"><span></span><span></span><span></span></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="features" class="lp-section lp-reveal">
    <div class="lp-section-head">
      <h2>هر روش ارسالی که نیاز دارید</h2>
      <p>پنج حالت ارسال، یک موتور واحد — بدون افزونه‌ی جداگانه و بدون هزینه‌ی اضافه.</p>
    </div>
    <div class="lp-bento">
      <article class="lp-card lp-bento-flag">
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
      <article class="lp-card lp-bento-wide">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
        </span>
        <h3>گزارش و آمار تفصیلی</h3>
        <p>وضعیت هر پیامک، نمودار هفتگی ارسال، و آمار به تفکیک شماره، مشتری و اپراتور را لحظه‌ای ببینید.</p>
      </article>
      <article class="lp-card lp-bento-c1">
        <span class="lp-icon lp-icon-tint-b">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
        </span>
        <h3>منشی پیامک</h3>
        <p>به پیامک‌های دریافتی بر اساس قوانین از‌پیش‌تعیین‌شده، بدون دخالت دستی، پاسخ خودکار بدهید.</p>
      </article>
      <article class="lp-card lp-bento-c2">
        <span class="lp-icon lp-icon-tint-a">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
        </span>
        <h3>ورود دومرحله‌ای</h3>
        <p>حساب‌های حساس را با کد پیامکی یک‌بارمصرف محافظت کنید — قابل‌فعال‌سازی برای یک کاربر یا کل مجموعه.</p>
      </article>
      <article class="lp-card lp-bento-c3">
        <span class="lp-icon lp-icon-tint-d">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/></svg>
        </span>
        <h3>ارسال مستقیم و دوره‌ای</h3>
        <p>پیامک را همین حالا بفرستید یا برای تاریخ و ساعت مشخص — با تقویم شمسی — زمان‌بندی کنید.</p>
      </article>
      <article class="lp-card lp-bento-c4">
        <span class="lp-icon lp-icon-tint-f">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        </span>
        <h3>ارسال تدریجی</h3>
        <p>ارسال را به‌صورت پلکانی و با فاصله‌ی زمانی کنترل‌شده انجام دهید تا نرخ تحویل بالاتر بماند.</p>
      </article>
      <article class="lp-card lp-bento-c5">
        <span class="lp-icon lp-icon-tint-e">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
        </span>
        <h3>نظیر به نظیر</h3>
        <p>یک فایل اکسل یا CSV آپلود کنید و به هر مخاطب متنی کاملاً متفاوت، دقیقاً همان‌طور که نوشته‌اید، بفرستید.</p>
      </article>
      <article class="lp-card lp-bento-c6">
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
        <div class="lp-compare-highlight">ELLSMS</div>
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
        <div class="lp-compare-yes">منشی پیامک، پاسخ خودکار و آنی</div>
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
      <?php foreach ($packages as $p): ?>
        <div class="lp-price-card<?= $p['is_featured'] ? ' is-featured' : '' ?>">
          <?php if ($p['is_featured']): ?><span class="lp-price-badge">پیشنهاد ویژه</span><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <div class="lp-price-amount"><?= to_persian_digits(number_format((int)$p['price_rial'])) ?> <span>ریال</span></div>
          <div class="lp-price-credit"><?= to_persian_digits(number_format((int)$p['credit_amount'])) ?> واحد اعتبار</div>
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
      <p>بدون فرایند ثبت‌نام پیچیده، بدون نصب چیزی روی دستگاه شما.</p>
    </div>
    <ol class="lp-steps">
      <li>
        <span class="lp-step-no">۱</span>
        <h3>دریافت دسترسی</h3>
        <p>مدیر مجموعه‌ی شما حساب کاربری‌تان را به پنل متصل می‌کند — بدون نیاز به ساخت حساب جداگانه.</p>
      </li>
      <li>
        <span class="lp-step-no">۲</span>
        <h3>شارژ اعتبار</h3>
        <p>از طریق درگاه زرین‌پال، در چند ثانیه اعتبار بخرید؛ اعتبار همان لحظه در پنل قابل استفاده است.</p>
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
      <article class="lp-usecase">
        <span class="lp-icon lp-icon-tint-a">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.6 13.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 2-1.6L23 6H6"/></svg>
        </span>
        <h3>فروشگاه اینترنتی</h3>
        <p>کد تخفیف، پیگیری سفارش و یادآوری سبد خرید رهاشده — با قالب پویا و به نام هر مشتری.</p>
      </article>
      <article class="lp-usecase">
        <span class="lp-icon lp-icon-tint-b">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="M12 8v4l3 2"/></svg>
        </span>
        <h3>کلینیک و مطب</h3>
        <p>یادآوری خودکار نوبت، پیش از ساعت مراجعه — بدون تماس تلفنی و بدون فراموشی.</p>
      </article>
      <article class="lp-usecase">
        <span class="lp-icon lp-icon-tint-c">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h3>باشگاه مشتریان</h3>
        <p>اطلاع‌رسانی تخفیف‌های ویژه و امتیاز وفاداری به گروه‌های مشخصی از مخاطبین.</p>
      </article>
      <article class="lp-usecase">
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
        <div class="lp-guide-body">حساب کاربری شما را مدیر مجموعه‌تان به پنل متصل می‌کند؛ نیازی به ثبت‌نام جداگانه نیست. برای اطلاعات بیشتر با ما <a href="/contact.php">تماس بگیرید</a>.</div>
      </details>
      <details class="lp-guide-item">
        <summary>خرید اعتبار چقدر طول می‌کشد؟</summary>
        <div class="lp-guide-body">پرداخت از طریق درگاه رسمی زرین‌پال انجام می‌شود و اعتبار بلافاصله پس از تأیید تراکنش در پنل شما فعال است.</div>
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
    <p>به پنل وارد شوید و اولین ارسال خود را در کمتر از یک دقیقه انجام دهید.</p>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
