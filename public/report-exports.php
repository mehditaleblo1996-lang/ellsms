<?php
/**
 * ELLSMS — report export list and download (Phase 8).
 *
 * Two jobs in one page, because they share the same authorization rule:
 *   - GET /report-exports.php              list this organization's exports and their state
 *   - GET /report-exports.php?download=ID  stream one finished file
 *
 * SECURITY SHAPE. The file lives in storage/exports/, OUTSIDE the web root, so it is not reachable
 * by URL — the only way to obtain one is through this authenticated endpoint. Ownership is checked
 * in SQL (report_export_get() scopes by organization), so changing the id in the URL yields "not
 * found" rather than another tenant's data. The on-disk name is random and carries no filter
 * information; the descriptive name the user sees is applied only in the Content-Disposition header.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

// Platform admins pass null to bypass the org filter, matching public/reports.php's existing admin
// rule. This does not widen any permission — an ordinary member remains scoped to their own org.
$scopeOrgId = is_admin() ? null : (int)($me['organization_id'] ?? 0);

/* ---------- Download ---------- */
if (isset($_GET['download'])) {
    $exportId = (int)$_GET['download'];
    $export = $exportId > 0 ? report_export_get($exportId, $scopeOrgId) : null;

    // One indistinguishable response for "does not exist", "belongs to another organization" and
    // "not finished". Distinguishing them would let a caller probe which export ids exist.
    if ($export === null || ($export['status'] ?? '') !== 'ready' || empty($export['storage_key'])) {
        http_response_code(404);
        exit('یافت نشد');
    }

    try {
        $path = report_export_path((string)$export['storage_key']);
    } catch (InvalidArgumentException) {
        http_response_code(404);
        exit('یافت نشد');
    }

    if (!is_file($path)) {
        // Row says ready but the file is gone — most likely the retention sweep removed it.
        http_response_code(410);
        exit('این خروجی دیگر در دسترس نیست.');
    }

    audit((int)$me['id'], 'report_export_download', 'export_id=' . $exportId);

    $name = (string)($export['download_filename'] ?? '');
    if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        // Never echo an unvalidated stored value into a response header.
        $name = 'ellsms-report-' . $exportId . '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    // readfile() streams in chunks rather than materializing the file in memory, so a 500 MB export
    // downloads with the same footprint as a 5 KB one.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    exit;
}

/* ---------- List ---------- */
$pageTitle = 'خروجی‌های گزارش';
$active = 'reports';

$exports = report_export_list((int)($me['organization_id'] ?? 0), 20);

$statusLabels = [
    'queued'     => ['در صف', 'warn'],
    'processing' => ['در حال آماده‌سازی', 'warn'],
    'ready'      => ['آماده دانلود', 'ok'],
    'failed'     => ['ناموفق', 'err'],
    'expired'    => ['منقضی شده', 'muted'],
    'cancelled'  => ['لغو شده', 'muted'],
];

$fmtBytes = static function (int $b): string {
    if ($b < 1024) return to_persian_digits((string)$b) . ' بایت';
    if ($b < 1048576) return to_persian_digits(number_format($b / 1024, 1)) . ' کیلوبایت';
    return to_persian_digits(number_format($b / 1048576, 1)) . ' مگابایت';
};

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>خروجی‌های گزارش</h2>
  <p class="hint">
    خروجی‌های بزرگ در پس‌زمینه آماده می‌شوند تا مرورگر منتظر نماند. پس از آماده شدن، فایل از همین
    صفحه قابل دانلود است. فایل‌ها پس از مدت مشخصی به‌صورت خودکار حذف می‌شوند.
  </p>

  <div class="table-wrap">
  <table>
    <tr>
      <th>شناسه</th>
      <th>وضعیت</th>
      <th>پیشرفت</th>
      <th>حجم</th>
      <th>زمان درخواست</th>
      <th>انقضا</th>
      <th>عملیات</th>
    </tr>
    <?php foreach ($exports as $x): ?>
      <?php [$label, $cls] = $statusLabels[$x['status']] ?? [$x['status'], 'muted']; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)(int)$x['id']) ?></td>
        <td><span class="badge badge-<?= e($cls) ?>"><?= e($label) ?></span></td>
        <td class="num">
          <?php if ((int)$x['total_rows'] > 0): ?>
            <?= to_persian_digits(number_format((int)$x['exported_rows'])) ?> /
            <?= to_persian_digits(number_format((int)$x['total_rows'])) ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="num"><?= (int)$x['file_bytes'] > 0 ? $fmtBytes((int)$x['file_bytes']) : '—' ?></td>
        <td><?= e(jdate((string)$x['created_at'])) ?></td>
        <td><?= !empty($x['expires_at']) ? e(jdate((string)$x['expires_at'])) : '—' ?></td>
        <td>
          <?php if ($x['status'] === 'ready'): ?>
            <a class="btn btn-sm" href="?download=<?= (int)$x['id'] ?>">دانلود</a>
          <?php elseif ($x['status'] === 'failed'): ?>
            <span class="hint"><?= e((string)($x['error_message'] ?? 'خطا')) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$exports): ?>
      <tr><td colspan="7" class="empty">هنوز خروجی‌ای درخواست نشده است.</td></tr>
    <?php endif; ?>
  </table>
  </div>

  <p style="margin-top:16px"><a class="btn" href="/reports.php">← بازگشت به گزارش‌ها</a></p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
