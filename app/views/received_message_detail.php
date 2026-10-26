<?php
/**
 * The detail drawer's content for one received message (public/inbox.php ?_part=detail).
 * Expects $m = received_report_find() (null: not found or not on the viewer's lines), $isAdmin.
 */
if ($m === null): ?>
  <div class="report-detail">
    <h2>پیام پیدا نشد</h2>
    <p class="hint">این پیام وجود ندارد یا روی خط‌های حساب شما دریافت نشده است.</p>
  </div>
<?php return; endif;

$content = (string)($m['content'] ?? '');
$statusLabels = [
    'pending' => 'در صف', 'processing' => 'در حال ارسال', 'delivered' => 'ارسال شد', 'failed' => 'ناموفق، تلاش دوباره',
    'dead' => 'ناموفق', 'dead_letter' => 'ناموفق', 'cancelled' => 'لغو شد',
];
$field = static function (string $label, string $valueHtml): string {
    return '<div class="detail-field"><dt>' . e($label) . '</dt><dd>' . $valueHtml . '</dd></div>';
};
$text = static fn(?string $v, string $class = ''): string => ($v === null || trim($v) === '') ? '<span class="muted">—</span>'
    : ($class !== '' ? '<span class="' . $class . '">' . e($v) . '</span>' : e($v));
?>
<article class="report-detail">
  <header class="report-detail-head">
    <div>
      <span class="hint">پیام دریافتی <span class="ltr"><?= e((string)$m['id']) ?></span></span>
      <h2>گزارش کامل پیام دریافتی</h2>
    </div>
    <span class="forward-status is-<?= e($m['forward_state']) ?>"><?= e($m['forward_label']) ?></span>
  </header>

  <section class="detail-message">
    <div class="detail-bubble" dir="auto"><?= $content !== '' ? e($content) : '<span class="muted">این پیام متنی ندارد.</span>' ?></div>
    <div class="detail-message-meta">
      <span><?= to_persian_digits((string)$m['characters']) ?> نویسه</span>
      <span><?= to_persian_digits((string)$m['parts']) ?> بخش</span>
      <span class="ltr"><?= e($m['format']) ?></span>
    </div>
  </section>

  <section>
    <h3>مشخصات</h3>
    <dl class="detail-grid">
      <?= $field('فرستنده', $text((string)$m['originator'], 'msisdn')) ?>
      <?= $field('اپراتور فرستنده', operator_label($m['operator_name'], $m['operator_name'], 18)) ?>
      <?= $field('گیرنده (خط)', $text((string)$m['destination'], 'msisdn')) ?>
      <?= $field('مشتری صاحب خط', $m['owner_name'] !== '' ? e($m['owner_name']) . ($m['owner_username'] !== '' && $m['owner_username'] !== $m['owner_name'] ? ' <span class="muted">(' . e($m['owner_username']) . ')</span>' : '') : '<span class="muted">—</span>') ?>
      <?= $field('زمان دریافت', '<span class="num">' . received_report_datetime((string)$m['received_at']) . '</span>') ?>
      <?= $field('دریافت از', $m['store'] === 'ellsms' ? 'درگاه پیامک پنل' . ($m['gateway_name'] !== null ? ' — ' . e($m['gateway_name']) : '') : 'سامانه‌ی مرکزی') ?>
      <?php if (!empty($m['provider_message_id'])): ?><?= $field('شناسه نزد سرویس‌دهنده', $text((string)$m['provider_message_id'], 'ltr')) ?><?php endif; ?>
    </dl>
  </section>

  <section>
    <h3>فوروارد پیام</h3>
    <?php if ($m['forwards'] === []): ?>
      <p class="hint">این پیام به آدرس اینترنتی یا وب‌هوکی فوروارد نشده است. فوروارد را می‌توانید از «فوروارد پیامک به URL» یا «وب‌هوک‌ها» فعال کنید.</p>
    <?php else: ?>
      <ol class="detail-timeline">
        <?php foreach ($m['forwards'] as $fw): $ok = $fw['status'] === 'delivered'; $bad = in_array($fw['status'], ['dead', 'dead_letter'], true); ?>
          <li class="<?= $ok || $bad ? 'is-done' : 'is-waiting' ?><?= $bad ? ' is-failed' : '' ?>">
            <strong><?= $fw['channel'] === 'url' ? 'فوروارد به URL' : 'وب‌هوک' ?> — <?= e($statusLabels[$fw['status']] ?? $fw['status']) ?></strong>
            <span class="num"><?= $fw['completed_at'] ? sent_report_datetime((string)$fw['completed_at']) : '—' ?></span>
          </li>
          <li class="detail-forward-meta">
            <span class="ltr"><?= e(mb_strimwidth($fw['target'], 0, 70, '…')) ?></span>
            <?php if ((int)$fw['http_status'] > 0): ?><span class="ltr">HTTP <?= (int)$fw['http_status'] ?></span><?php endif; ?>
            <?php if ($fw['attempts'] > 1): ?><span><?= to_persian_digits((string)$fw['attempts']) ?> تلاش</span><?php endif; ?>
            <?php if (!empty($fw['error_code']) && !$ok): ?><span class="ltr"><?= e((string)$fw['error_code']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</article>
