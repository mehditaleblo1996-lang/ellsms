<?php
/* The end half of the sign-in card: a slider of what the panel does, closing with how to start.
   Each slide plays a looping scene drawn in HTML/CSS — it reads like a short video but loads no
   media file. The scenes are decoration (aria-hidden); each slide's title and line are real text.
   The slider is hidden on narrow screens (see login-slider.css), where the form stands alone.
   $registrationOpen decides whether the guide slide says "sign up" or "ask for an account". */
$registrationOpen = setting('registration_mode', 'approval') !== 'closed';
$slides = [
    ['tag' => 'پیامک هوشمند',   'title' => 'یک قالب، هزاران پیام شخصی',      'body' => 'فایل مخاطبین را بدهید؛ نام و کد هر نفر خودکار در متن پیام می‌نشیند.'],
    ['tag' => 'ارسال گروهی',    'title' => 'ارسال انبوه، یکجا یا تدریجی',     'body' => 'هزاران پیامک را در یک لحظه یا پلکانی و با فاصله‌ی کنترل‌شده بفرستید.'],
    ['tag' => 'گزارش لحظه‌ای',  'title' => 'وضعیت تحویل هر پیام، زنده',       'body' => 'نمودار ارسال و آمار به تفکیک شماره و اپراتور را همان لحظه ببینید.'],
    ['tag' => 'منشی پیامک',     'title' => 'پاسخ خودکار، حتی نیمه‌شب',        'body' => 'برای پیامک‌های دریافتی قانون بگذارید تا پنل بدون دخالت شما جواب بدهد.'],
    ['tag' => 'راهنمای شروع',   'title' => 'سه قدم تا اولین ارسال',            'body' => $registrationOpen
        ? 'ثبت‌نام کنید، اعتبار حساب را شارژ کنید و اولین پیامک را بفرستید.'
        : 'حساب را از مدیر سامانه بگیرید، اعتبار را شارژ کنید و اولین پیامک را بفرستید.'],
];
$total = count($slides);
$fa = static fn (int $n): string => strtr((string)$n, ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']);
?>
<section class="auth-stage ls" id="loginSlider" aria-roledescription="carousel" aria-label="امکانات ELLSMS">
  <div class="ls-ambient" aria-hidden="true"><i></i><i></i><i></i></div>

  <div class="ls-progress" role="tablist" aria-label="انتخاب اسلاید">
    <?php foreach ($slides as $i => $s): ?>
      <button type="button" role="tab" class="ls-tick<?= $i === 0 ? ' is-active' : '' ?>" id="lsTab<?= $i ?>" aria-controls="lsSlide<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>" aria-label="<?= e($s['tag']) ?>"><i></i></button>
    <?php endforeach; ?>
  </div>

  <div class="ls-track">
    <?php foreach ($slides as $i => $s): ?>
    <article class="ls-slide<?= $i === 0 ? ' is-active' : '' ?>" id="lsSlide<?= $i ?>" role="tabpanel" aria-labelledby="lsTab<?= $i ?>" aria-roledescription="slide" aria-label="<?= $fa($i + 1) ?> از <?= $fa($total) ?>"<?= $i === 0 ? '' : ' aria-hidden="true"' ?>>
      <div class="ls-scene ls-scene-<?= $i + 1 ?>" aria-hidden="true">
        <?php if ($i === 0): /* A template fills itself from contact rows, one personalised bubble per row. */ ?>
          <div class="sc-template">
            <span class="sc-label">قالب پیام</span>
            <p>سلام <b>{نام}</b>، سفارش <b>{کد}</b> ارسال شد.</p>
          </div>
          <div class="sc-rows">
            <span><b>سارا</b><em dir="ltr">4821</em></span>
            <span><b>علی</b><em dir="ltr">4822</em></span>
            <span><b>مریم</b><em dir="ltr">4823</em></span>
          </div>
          <div class="sc-out">
            <p class="sc-bubble">سلام <b>سارا</b>، سفارش <b>۴۸۲۱</b> ارسال شد.</p>
            <p class="sc-bubble">سلام <b>علی</b>، سفارش <b>۴۸۲۲</b> ارسال شد.</p>
            <p class="sc-bubble">سلام <b>مریم</b>، سفارش <b>۴۸۲۳</b> ارسال شد.</p>
          </div>

        <?php elseif ($i === 1): /* A hub sends out in three waves; each phone lights as its message lands. */ ?>
          <svg class="sc-net" viewBox="0 0 320 220">
            <g class="sc-links">
              <?php
              $nodes = [[40,40,1],[160,22,2],[280,40,1],[22,110,3],[298,110,3],[40,180,2],[160,198,1],[280,180,2],[96,30,3],[224,30,3],[96,190,3],[224,190,1]];
              foreach ($nodes as [$x, $y, $wave]): ?>
                <line x1="160" y1="110" x2="<?= $x ?>" y2="<?= $y ?>" class="w<?= $wave ?>"/>
              <?php endforeach; ?>
            </g>
            <g class="sc-pulses">
              <?php foreach ($nodes as [$x, $y, $wave]): ?>
                <circle r="3" class="w<?= $wave ?>" style="--x:<?= $x - 160 ?>px;--y:<?= $y - 110 ?>px" cx="160" cy="110"/>
              <?php endforeach; ?>
            </g>
            <g class="sc-phones">
              <?php foreach ($nodes as [$x, $y, $wave]): ?>
                <rect x="<?= $x - 7 ?>" y="<?= $y - 11 ?>" width="14" height="22" rx="3.5" class="w<?= $wave ?>"/>
              <?php endforeach; ?>
            </g>
            <g class="sc-hub">
              <circle cx="160" cy="110" r="30" class="sc-hub-ring"/>
              <rect x="143" y="96" width="34" height="27" rx="8"/>
              <path d="M148 120v9l9-7Z"/>
              <circle cx="152" cy="109.5" r="2.4" class="sc-dot"/><circle cx="160" cy="109.5" r="2.4" class="sc-dot"/><circle cx="168" cy="109.5" r="2.4" class="sc-dot"/>
            </g>
          </svg>
          <div class="sc-counter"><span class="sc-counter-num"></span><small>پیامک ارسال شد</small></div>

        <?php elseif ($i === 2): /* Bars grow, the delivery ring fills, and status rows flip to delivered. */ ?>
          <div class="sc-dash">
            <div class="sc-chart">
              <span class="sc-label">ارسال هفته</span>
              <div class="sc-bars">
                <?php foreach ([46, 64, 38, 80, 58, 92, 72] as $k => $h): ?>
                  <i style="--h:<?= $h ?>%;--k:<?= $k ?>"></i>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="sc-ring">
              <span class="sc-ring-dial">
                <svg viewBox="0 0 80 80"><circle cx="40" cy="40" r="32" class="sc-ring-bg"/><circle cx="40" cy="40" r="32" class="sc-ring-fill" pathLength="100"/></svg>
                <b>۹۸٪</b>
              </span>
              <small>نرخ تحویل</small>
            </div>
          </div>
          <ul class="sc-status">
            <li style="--k:0"><span dir="ltr">0912 ••• 4417</span><em><s>در صف</s><b>تحویل شد</b></em></li>
            <li style="--k:1"><span dir="ltr">0935 ••• 0821</span><em><s>در صف</s><b>تحویل شد</b></em></li>
            <li style="--k:2"><span dir="ltr">0901 ••• 7350</span><em><s>در صف</s><b>تحویل شد</b></em></li>
          </ul>

        <?php elseif ($i === 3): /* A customer asks, the panel types, the rule answers — under a night clock. */ ?>
          <div class="sc-phone">
            <div class="sc-phone-top"><span class="sc-moon"></span><time dir="ltr">02:14</time></div>
            <p class="sc-msg sc-in">سلام، ساعت کاری فروشگاه تا کیه؟</p>
            <div class="sc-replyslot">
              <p class="sc-typing"><i></i><i></i><i></i></p>
              <p class="sc-msg sc-auto">سلام! هر روز ۹ تا ۲۱ در خدمتیم.</p>
            </div>
            <span class="sc-rule">قانون: «ساعت کاری» ← پاسخ خودکار</span>
          </div>

        <?php else: /* A cursor walks the three steps; the line between them fills as each is done. */ ?>
          <ol class="sc-steps">
            <li style="--k:0">
              <span class="sc-step-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg></span>
              <b><?= $registrationOpen ? 'ثبت‌نام' : 'دریافت حساب' ?></b>
            </li>
            <li style="--k:1">
              <span class="sc-step-ico"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18M7 15h4"/></svg></span>
              <b>شارژ اعتبار</b>
            </li>
            <li style="--k:2">
              <span class="sc-step-ico"><svg viewBox="0 0 24 24"><path d="M21 3 10 14M21 3l-7 18-4-7-7-4Z"/></svg></span>
              <b>ارسال پیامک</b>
            </li>
          </ol>
          <div class="sc-rail"><i></i></div>
          <svg class="sc-cursor" viewBox="0 0 24 24"><path d="M5 3l14 8-6 1.6L10 19Z"/></svg>
          <p class="sc-done">اولین پیامک شما ارسال شد ✓</p>
        <?php endif; ?>
      </div>

      <div class="ls-caption">
        <span class="ls-tag"><?= e($s['tag']) ?></span>
        <h2 class="ls-title"><?= e($s['title']) ?></h2>
        <p class="ls-body"><?= e($s['body']) ?></p>
        <?php if ($i === $total - 1): ?>
          <a class="ls-cta" href="/guide">راهنمای کامل</a>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <div class="ls-controls">
    <span class="ls-count" aria-hidden="true"><b id="lsCurrent">۱</b> / <?= $fa($total) ?></span>
    <div class="ls-buttons">
      <button type="button" class="ls-btn" id="lsPrev" aria-label="اسلاید قبلی"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
      <button type="button" class="ls-btn" id="lsPlay" aria-label="توقف نمایش خودکار" aria-pressed="false" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" class="ls-ico-pause"><path d="M9 6v12M15 6v12"/></svg>
        <svg viewBox="0 0 24 24" aria-hidden="true" class="ls-ico-play"><path d="M8 5.5v13l10-6.5Z"/></svg>
      </button>
      <button type="button" class="ls-btn" id="lsNext" aria-label="اسلاید بعدی"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button>
    </div>
  </div>
</section>
