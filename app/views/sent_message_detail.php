<?php
/**
 * The detail drawer's content for one sent message (public/reports-v2.php ?_part=detail).
 * Expects $m = sent_report_find() (null: not found or not visible), $isAdmin, $canHide.
 */
if ($m === null): ?>
  <div class="report-detail">
    <h2>پیام پیدا نشد</h2>
    <p class="hint">این پیام وجود ندارد یا در دسترس حساب شما نیست.</p>
  </div>
<?php return; endif;

$status = (string)$m['report_status'];
$content = (string)($m['content'] ?? '');
$failed = $status === 'failed';
$deliveryAt = $m['delivered_at'] ?? null;
$checkedAt = $m['delivery_checked_at'] ?? null;
$error = trim((string)($m['error_message'] ?? ''));
$errorCode = trim((string)($m['error_code'] ?? ''));
$hiddenSource = ['bulk' => 'bulk_job', 'attempt' => 'attempt', 'legacy' => 'outbound'][$m['source']];
$hiddenId = $m['source'] === 'bulk' ? (int)$m['job_id'] : (int)$m['id'];
$isHidden = $canHide && hidden_messages_is_hidden($hiddenSource, $hiddenId);
$forwardLabels = ['pending' => 'در صف', 'processing' => 'در حال ارسال', 'delivered' => 'ارسال شد', 'failed' => 'ناموفق، تلاش دوباره', 'dead' => 'ناموفق', 'cancelled' => 'لغو شد'];

$field = static function (string $label, string $valueHtml): string {
    return '<div class="detail-field"><dt>' . e($label) . '</dt><dd>' . $valueHtml . '</dd></div>';
};
$text = static fn(?string $v, string $class = ''): string => ($v === null || trim($v) === '') ? '<span class="muted">—</span>'
    : ($class !== '' ? '<span class="' . $class . '">' . e($v) . '</span>' : e($v));

// Timeline: requested -> handed to the provider -> final state.
$steps = [['در صف / درخواست ثبت شد', sent_report_datetime($m['requested_at'] ?? null), true]];
if ($m['source'] === 'bulk') {
    $steps[] = ['تحویل به سرویس‌دهنده', sent_report_datetime($m['sent_at'] ?? null), !empty($m['sent_at'])];
} else {
    $steps[] = ['تحویل به سرویس‌دهنده', sent_report_datetime($m['sent_at'] ?? $m['requested_at'] ?? null), $status !== 'queued'];
}
if ($status === 'delivered') {
    $steps[] = ['تحویل به گوشی گیرنده', sent_report_datetime($deliveryAt), true];
} elseif ($failed) {
    $steps[] = ['ناموفق', sent_report_datetime($checkedAt ?? $m['sent_at'] ?? null), true];
} elseif ($status === 'cancelled') {
    $steps[] = ['لغو شد', '', true];
} else {
    $steps[] = ['گزارش تحویل', 'هنوز نرسیده', false];
}
?>
<article class="report-detail">
  <header class="report-detail-head">
    <div>
      <span class="hint">پیام <span class="ltr"><?= e($m['ref']) ?></span> · <?= e($m['type_label']) ?></span>
      <h2>گزارش کامل پیام</h2>
    </div>
    <span class="badge badge-<?= e(sent_report_status_class($status)) ?>"><?= e($m['status_label']) ?></span>
  </header>
  <?php if ($isHidden): ?><p><?= hidden_messages_badge() ?></p><?php endif; ?>

  <section class="detail-message">
    <div class="detail-bubble" dir="auto"><?= $content !== '' ? e($content) : '<span class="muted">متن این پیام ثبت نشده است.</span>' ?></div>
    <div class="detail-message-meta">
      <span><?= to_persian_digits((string)$m['characters']) ?> نویسه</span>
      <span><?= to_persian_digits((string)$m['parts']) ?> بخش</span>
      <span class="ltr"><?= e($m['format']) ?></span>
    </div>
  </section>

  <section>
    <h3>مسیر پیام</h3>
    <dl class="detail-grid">
      <?= $field('فرستنده', $text((string)($m['originator'] ?? ''), 'msisdn')) ?>
      <?= $field('گیرنده', $text($m['destination_display'], 'msisdn')) ?>
      <?= $field('اپراتور گیرنده', operator_label($m['operator_code'], $m['operator_name'], 18)) ?>
      <?= $field('کاربر', e($m['username']) . ($m['user_full_name'] !== '' ? ' <span class="muted">(' . e($m['user_full_name']) . ')</span>' : '')) ?>
      <?php if ($isAdmin): ?>
        <?= $field('درگاه', $text($m['gateway_name'])) ?>
        <?= $field('مسیر', $text($m['route_name'])) ?>
      <?php endif; ?>
      <?= $field('شناسه نزد سرویس‌دهنده', $text(isset($m['provider_message_id']) ? (string)$m['provider_message_id'] : null, 'ltr')) ?>
      <?= $field('وضعیت اعلام‌شده‌ی سرویس‌دهنده', !empty($m['provider_status']) ? $text((string)$m['provider_status'], 'ltr')
          : (!empty($m['delivery_status']) ? e(report_delivery_status_label((string)$m['delivery_status'])) : '<span class="muted">—</span>')) ?>
    </dl>
  </section>

  <section>
    <h3>زمان‌ها</h3>
    <ol class="detail-timeline">
      <?php foreach ($steps as [$label, $when, $done]): ?>
        <li class="<?= $done ? 'is-done' : 'is-waiting' ?><?= $done && $failed && $label === 'ناموفق' ? ' is-failed' : '' ?>">
          <strong><?= e($label) ?></strong><span class="num"><?= $when !== '' ? $when : '—' ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if (isset($m['delivery_attempts']) && (int)$m['delivery_attempts'] > 0): ?>
      <p class="hint">وضعیت تحویل <?= to_persian_digits((string)(int)$m['delivery_attempts']) ?> بار از سرویس‌دهنده استعلام شده؛ آخرین بار <?= sent_report_datetime($checkedAt) ?: '—' ?>.</p>
    <?php endif; ?>
  </section>

  <?php if ($failed && ($error !== '' || $errorCode !== '')): ?>
  <section>
    <h3>علت خطا</h3>
    <p class="flash flash-error detail-error"><?= $errorCode !== '' ? '<span class="ltr">' . e($errorCode) . '</span> — ' : '' ?><?= e($error !== '' ? $error : 'جزئیاتی ثبت نشده است.') ?></p>
  </section>
  <?php endif; ?>

  <section>
    <h3>هزینه و پیگیری</h3>
    <dl class="detail-grid">
      <?= $field('هزینه‌ی کسرشده', $m['cost'] !== null ? to_persian_digits(number_format((int)$m['cost'])) . ' ریال' : '<span class="muted">ثبت نشده</span>') ?>
      <?php if ($m['source'] === 'bulk'): ?>
        <?= $field('ارسال حجیم', '<a href="/messages/bulk-jobs?id=' . (int)$m['job_id'] . '">#' . to_persian_digits((string)(int)$m['job_id']) . ' ' . e((string)($m['job_title'] ?? '')) . '</a>') ?>
      <?php elseif ($m['source'] === 'attempt' && ($m['reference_type'] ?? '') === 'schedule'): ?>
        <?= $field('زمان‌بندی', '<a href="/schedules.php">#' . e((string)($m['reference_id'] ?? '')) . '</a>') ?>
      <?php endif; ?>
      <?php if ($m['dlr_forward'] !== null): $fw = $m['dlr_forward']; ?>
        <?= $field('ارسال گزارش تحویل به URL شما', e($forwardLabels[$fw['status']] ?? (string)$fw['status'])
            . ((int)$fw['http_status'] > 0 ? ' <span class="muted ltr">HTTP ' . (int)$fw['http_status'] . '</span>' : '')
            . ((int)$fw['attempt_count'] > 1 ? ' <span class="muted">(' . to_persian_digits((string)(int)$fw['attempt_count']) . ' تلاش)</span>' : '')) ?>
      <?php endif; ?>
    </dl>
  </section>

  <footer class="report-detail-actions">
    <?php if ($m['source'] === 'attempt'): ?><a class="btn btn-sm" href="/message-detail.php?attempt=<?= (int)$m['id'] ?>">تاریخچه‌ی کامل تحویل</a><?php endif; ?>
    <?php if ($m['source'] === 'bulk'): ?><a class="btn btn-sm" href="/message-detail.php?job=<?= (int)$m['job_id'] ?>">تاریخچه‌ی تحویل این ارسال حجیم</a><?php endif; ?>
    <?php if ($canHide): ?>
      <?= hidden_messages_admin_button($hiddenSource, $hiddenId, $isHidden) ?>
      <?php if ($m['source'] === 'bulk'): ?><span class="hint">حذف و بازگردانی برای کل این ارسال حجیم انجام می‌شود.</span><?php endif; ?>
    <?php endif; ?>
  </footer>
</article>
