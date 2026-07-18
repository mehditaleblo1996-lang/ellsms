<?php
require_once __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'راهنمای استفاده';
$metaDescription = 'راهنمای گام‌به‌گام استفاده از پنل ELLSMS.';
$articles = db()->query('SELECT * FROM ellsms_guide_articles WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/public_header.php';
?>
  <section class="lp-section lp-guide">
    <div class="lp-section-head">
      <h2>راهنمای استفاده</h2>
      <p>پاسخ سؤالات رایج و نحوه‌ی کار با پنل، قدم‌به‌قدم.</p>
    </div>
    <?php if ($articles): ?>
      <div class="lp-guide-list">
        <?php foreach ($articles as $i => $a): ?>
          <details class="lp-guide-item"<?= $i === 0 ? ' open' : '' ?>>
            <summary><?= e($a['title']) ?></summary>
            <div class="lp-guide-body"><?= nl2br(e($a['body'])) ?></div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="hint" style="text-align:center">راهنما هنوز از پنل مدیریت اضافه نشده است.</p>
    <?php endif; ?>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
