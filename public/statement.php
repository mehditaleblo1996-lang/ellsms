<?php
/**
 * ELLSMS — the monthly statement («صورت‌حساب ماهانه», app/Statement.php, docs/statement.md): one Jalali
 * month of an account's balance, charges, refunds and messages, on the page, as a PDF or as a printable
 * page. A platform admin picks any account; everyone else sees only their own. Read-only.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Statement.php';
$me = require_login();
$isAdmin = $me['role'] === 'admin';
$pageTitle = 'صورت‌حساب ماهانه';
$active = 'statement';

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
[$jy, $jm] = statement_requested_month($_GET, $now);

// Whose statement: an admin's choice (id or username), anyone else's own.
$userId = (int)$me['id'];
$username = '';
if ($isAdmin) {
    $userId = max(0, (int)from_persian_digits((string)($_GET['user_id'] ?? '')));
    $username = trim((string)($_GET['username'] ?? ''));
    if ($userId === 0 && $username !== '') {
        $st = db()->prepare('SELECT id FROM user_ WHERE username = ?');
        $st->execute([$username]);
        $userId = (int)($st->fetchColumn() ?: 0);
        if ($userId === 0) {
            flash('error', 'کاربری با این نام کاربری پیدا نشد.');
        }
    }
}

$statement = $userId > 0 ? statement_build($userId, $jy, $jm, $now) : null;
$brand = current_brand();
$format = (string)($_GET['format'] ?? '');
$query = ['y' => $jy, 'm' => $jm] + ($isAdmin && $userId > 0 ? ['user_id' => $userId] : []);

if ($statement !== null && $format === 'pdf') {
    $pdf = price_quote_render_pdf(statement_render_html($statement, $brand, 'pdf'));
    if ($pdf === null) {
        flash('error', 'ساخت PDF روی این سرور ممکن نشد؛ نسخه‌ی چاپی را باز کنید و از پنجره‌ی چاپ «ذخیره به PDF» را بزنید.');
        redirect('/statement.php?' . http_build_query($query));
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . statement_filename($statement) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if ($statement !== null && $format === 'print') {
    header('Content-Type: text/html; charset=utf-8');
    echo statement_render_html($statement, $brand, 'print');
    exit;
}

[$cy, $cm] = statement_jalali_month($now);
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>صورت‌حساب ماهانه</h2>
  <p class="hint">
    خلاصه‌ی مالی یک ماه<?= $isAdmin ? ' هر حساب' : ' حساب شما' ?>: موجودی ابتدا و پایان ماه، خرید اعتبار و افزایش‌ها، هزینه‌ی پیامک و
    مبالغ برگشتی پیام‌های ناموفق یا تحویل‌نشده، تعداد پیام و پارت به تفکیک اپراتور و گردش روزانه. همه‌چیز از همان تراکنش‌های
    «تاریخچه مالی» خوانده می‌شود و با تغییر تعرفه عوض نمی‌شود. مبالغ به ریال است.
  </p>
  <form method="get" class="toolbar">
    <?php if ($isAdmin): ?>
      <label>نام کاربری<input type="text" name="username" value="<?= e($username) ?>" class="ltr" style="width:140px"></label>
      <label>User ID<input type="number" name="user_id" value="<?= $userId ?: '' ?>" class="ltr" style="width:100px"></label>
    <?php endif; ?>
    <label>ماه
      <select name="m">
        <?php foreach (JALALI_MONTHS as $num => $name): ?>
          <option value="<?= (int)$num ?>"<?= $num === $jm ? ' selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>سال
      <select name="y">
        <?php for ($y = $cy; $y >= max(1390, $cy - 5); $y--): ?>
          <option value="<?= $y ?>"<?= $y === $jy ? ' selected' : '' ?>><?= e(to_persian_digits((string)$y)) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <button class="btn btn-primary">نمایش</button>
  </form>
  <?php if ($statement !== null): ?>
    <div class="toolbar">
      <a class="btn btn-primary" href="/statement.php?<?= e(http_build_query($query + ['format' => 'pdf'])) ?>">دانلود PDF</a>
      <a class="btn" href="/statement.php?<?= e(http_build_query($query + ['format' => 'print'])) ?>" target="_blank" rel="noopener">نسخه‌ی چاپی</a>
    </div>
  <?php elseif ($isAdmin): ?>
    <p class="hint">برای دیدن صورت‌حساب، نام کاربری یا شناسه‌ی حساب را وارد کنید.</p>
  <?php endif; ?>
</div>

<?php if ($statement !== null): ?>
<div class="card statement-card">
  <style><?= statement_css('/assets/fonts') ?> .statement-card .statement { font-size: 13px; }</style>
  <?= statement_render_body($statement, $brand) ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
