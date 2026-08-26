<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Onboarding.php';
$me = require_login();
if ($me['role'] === 'admin') redirect('/index.php');

$pageTitle = 'شروع کار';
$active = 'onboarding';
$status = onboarding_status($me);

$kycLabels = [
    'draft' => 'شروع نشده',
    'submitted' => 'ارسال شده',
    'under_review' => 'در حال بررسی',
    'needs_correction' => 'نیازمند اصلاح',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card" style="overflow:hidden">
  <div style="display:flex;gap:24px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <div class="hint">راه‌اندازی حساب ELLSMS</div>
      <h2 style="margin:5px 0 8px"><?= $status['complete'] ? 'حساب شما آماده استفاده است ✓' : 'چند قدم تا آماده‌شدن کامل حساب' ?></h2>
      <p class="hint" style="margin:0">این مراحل از وضعیت واقعی حساب محاسبه می‌شوند و نیازی به علامت‌زدن دستی ندارند.</p>
    </div>
    <div style="min-width:180px;text-align:center">
      <div style="font-size:2rem;font-weight:800" class="ltr"><?= to_persian_digits((string)$status['progress']) ?>%</div>
      <div style="height:9px;background:#eef0f8;border-radius:999px;overflow:hidden;margin-top:8px">
        <div style="height:100%;width:<?= (int)$status['progress'] ?>%;background:linear-gradient(90deg,#5b36f2,#315cff)"></div>
      </div>
    </div>
  </div>
</div>

<?php if ($status['video_url'] !== ''): ?>
<div class="card">
  <h2><?= e($status['video_title']) ?></h2>
  <p class="hint">این آموزش اختیاری است و مدیر می‌تواند لینک آن را از تنظیمات تغییر دهد یا حذف کند.</p>
  <a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="<?= e($status['video_url']) ?>">مشاهده ویدیو ↗</a>
</div>
<?php endif; ?>

<div class="card">
  <h2>مراحل شروع کار</h2>
  <div style="display:grid;gap:12px">
    <?php foreach ($status['steps'] as $index => $step): ?>
      <?php $done = !empty($step['done']); ?>
      <div style="display:flex;align-items:center;gap:14px;padding:16px;border:1px solid #e7e9f2;border-radius:14px;<?= $done ? 'opacity:.78' : '' ?>">
        <div style="width:38px;height:38px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:<?= $done ? '#e7f8ef' : '#eef0ff' ?>;color:<?= $done ? '#16784a' : '#3d46d9' ?>">
          <?= $done ? '✓' : to_persian_digits((string)($index + 1)) ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <strong><?= e($step['label']) ?></strong>
            <?php if (!empty($step['optional'])): ?><span class="badge">اختیاری</span><?php endif; ?>
            <?php if (!empty($step['meta'])): ?>
              <span class="hint<?= $step['key']==='profile' ? ' ltr' : '' ?>">
                <?= $step['key']==='kyc' ? e($kycLabels[$step['meta']] ?? $step['meta']) : e((string)$step['meta']) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="hint" style="margin-top:4px"><?= e($step['description']) ?></div>
        </div>
        <?php if (!$done && !empty($step['href'])): ?>
          <a class="btn btn-sm btn-primary" href="<?= e($step['href']) ?>">انجام مرحله</a>
        <?php elseif ($done): ?>
          <span class="badge badge-ok">انجام شد</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <strong>می‌خواهید مستقیم وارد پنل شوید؟</strong>
      <div class="hint">این راهنما جلوی استفاده از پنل را نمی‌گیرد؛ محدودیت‌های KYC در فاز بعد به‌صورت جداگانه اعمال می‌شوند.</div>
    </div>
    <a class="btn" href="/index.php">رفتن به داشبورد</a>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
