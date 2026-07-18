<?php
require_once __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'پنل هوشمند پیامک';
$metaDescription = 'ارسال مستقیم، دوره‌ای و تدریجی، پیامک هوشمند با قالب پویا، منشی پیامک خودکار و گزارش لحظه‌ای — همه در یک پنل پیامکی یکپارچه.';
$slides   = db()->query('SELECT * FROM ellsms_slides WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$packages = db()->query('SELECT * FROM ellsms_pricing_packages WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/public_header.php';
?>
  <?php if ($slides): ?>
    <section class="lp-slider-full" id="lpSlider">
      <div class="lp-slider-viewport">
        <?php foreach ($slides as $i => $s): ?>
          <div class="lp-slide<?= $i === 0 ? ' is-active' : '' ?>">
            <img src="/assets/img/slides/<?= e($s['image']) ?>" alt="<?= e($s['title']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
            <div class="lp-slide-caption">
              <div class="lp-slide-caption-inner">
                <h3><?= e($s['title']) ?></h3>
                <?php if ($s['body']): ?><p><?= e($s['body']) ?></p><?php endif; ?>
                <?php if ($s['link_url']): ?><a href="<?= e($s['link_url']) ?>" class="btn btn-primary btn-sm">مشاهده</a><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($slides) > 1): ?>
        <button type="button" class="lp-slider-nav lp-slider-prev" aria-label="اسلاید قبلی">‹</button>
        <button type="button" class="lp-slider-nav lp-slider-next" aria-label="اسلاید بعدی">›</button>
        <div class="lp-slider-dots">
          <?php foreach ($slides as $i => $s): ?>
            <button type="button" class="lp-dot<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= $i ?>" aria-label="اسلاید <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="lp-hero">
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
    <?php if (!$slides): ?>
      <div class="lp-hero-panel" aria-hidden="true">
        <div class="lp-hero-panel-bar">
          <span></span><span></span><span></span>
        </div>
        <div class="lp-hero-panel-body">
          <div class="stat stat-accent"><div class="stat-label">ارسال امروز</div><div class="stat-value">۱۲,۴۸۰</div></div>
          <div class="stat"><div class="stat-label">در صف زمان‌بندی</div><div class="stat-value">۳۶</div></div>
          <div class="stat"><div class="stat-label">نرخ تحویل</div><div class="stat-value">۹۸٪</div></div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section id="features" class="lp-section">
    <div class="lp-section-head">
      <h2>هر روش ارسالی که نیاز دارید</h2>
      <p>شش حالت ارسال، یک موتور واحد — بدون افزونه‌ی جداگانه و بدون هزینه‌ی اضافه.</p>
    </div>
    <div class="lp-grid">
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7Z"/></svg>
        </span>
        <h3>ارسال مستقیم و دوره‌ای</h3>
        <p>پیامک را همین حالا بفرستید یا برای تاریخ و ساعت مشخص — با تقویم شمسی — زمان‌بندی کنید.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        </span>
        <h3>ارسال تدریجی</h3>
        <p>ارسال را به‌صورت پلکانی و با فاصله‌ی زمانی کنترل‌شده انجام دهید تا نرخ تحویل بالاتر بماند.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
        </span>
        <h3>نظیر به نظیر</h3>
        <p>یک فایل اکسل یا CSV آپلود کنید و به هر مخاطب متنی کاملاً متفاوت، دقیقاً همان‌طور که نوشته‌اید، بفرستید.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.5L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/></svg>
        </span>
        <h3>پیامک هوشمند</h3>
        <p>با قالب‌های پویا مثل <code class="kbd">{نام}</code> و <code class="kbd">{مبلغ}</code>، هزاران پیام شخصی‌سازی‌شده را در یک ارسال بفرستید — قالب همان جدول شماست.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
        </span>
        <h3>منشی پیامک</h3>
        <p>به پیامک‌های دریافتی بر اساس قوانین از‌پیش‌تعیین‌شده، بدون دخالت دستی، پاسخ خودکار بدهید.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
        </span>
        <h3>گزارش و آمار تفصیلی</h3>
        <p>وضعیت هر پیامک، نمودار هفتگی ارسال، و آمار به تفکیک شماره، مشتری و اپراتور را لحظه‌ای ببینید.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m5-2.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 1 0 0-6M6 8a3 3 0 1 0 0-6"/></svg>
        </span>
        <h3>مخاطبین و لیست سیاه</h3>
        <p>مخاطبین را گروه‌بندی کنید و با یک لیست سیاه، شماره‌های مسدود را پیش از هر ارسال به‌طور خودکار فیلتر کنید.</p>
      </article>
      <article class="lp-card">
        <span class="lp-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
        </span>
        <h3>ورود دومرحله‌ای</h3>
        <p>حساب‌های حساس را با کد پیامکی یک‌بارمصرف محافظت کنید — قابل‌فعال‌سازی برای یک کاربر یا کل مجموعه.</p>
      </article>
    </div>
  </section>

  <?php if ($packages): ?>
  <section id="pricing" class="lp-section">
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

  <section id="how" class="lp-section lp-how">
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

  <section id="trust" class="lp-section lp-trust">
    <div class="lp-section-head">
      <h2>ساخته‌شده برای اطمینان</h2>
    </div>
    <div class="lp-grid lp-grid-narrow">
      <div class="lp-trust-item">
        <h4>راست‌به‌چپ و تقویم شمسی</h4>
        <p>تمام پنل فارسی و راست‌به‌چپ است؛ تاریخ‌ها با تقویم جلالی و اعداد فارسی نمایش داده می‌شوند.</p>
      </div>
      <div class="lp-trust-item">
        <h4>پرداخت امن با زرین‌پال</h4>
        <p>خرید اعتبار مستقیماً از طریق API رسمی زرین‌پال انجام می‌شود؛ هر پرداخت فقط یک‌بار اعتبار می‌دهد.</p>
      </div>
      <div class="lp-trust-item">
        <h4>پردازش پیوسته در پس‌زمینه</h4>
        <p>زمان‌بندی‌ها، منشی پیامک و ارسال‌های انبوه توسط یک پردازشگر پیوسته دنبال می‌شوند، نه فقط هنگام باز بودن مرورگر.</p>
      </div>
      <div class="lp-trust-item">
        <h4>مدیریت متمرکز کاربران</h4>
        <p>مدیر می‌تواند دسترسی، اعتبار و شماره‌های اختصاصی هر کاربر را از یک‌جا کنترل کند.</p>
      </div>
    </div>
  </section>

  <section class="lp-cta">
    <h2>آماده‌اید شروع کنید؟</h2>
    <p>به پنل وارد شوید و اولین ارسال خود را در کمتر از یک دقیقه انجام دهید.</p>
    <a href="<?= e($primaryHref) ?>" class="btn btn-primary"><?= e($primaryLabel) ?></a>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
