<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'گزارش ارسال';
$active = 'reports';

// Phase 7: platform admins keep their existing unrestricted bypass; an ordinary org member needs
// REPORTS_VIEW — granted to every built-in role by default today (app/rbac.php), so this is
// explicit fail-closed enforcement on top of already-universal access, not a new restriction.
if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

/**
 * outbound_message and the delivery worker persist MySQL DATETIME values in UTC. The panel itself
 * runs in Asia/Tehran, so feeding those naive UTC strings straight to jdate() makes PHP interpret
 * 07:45 as 07:45 Tehran instead of 11:15 Tehran. Convert explicitly at the presentation boundary.
 */
function report_local_mysql_time(?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function report_jdate(?string $value): string {
    $local = report_local_mysql_time($value);
    return $local === '' ? '' : jdate($local);
}

/* ---------- Filters ---------- */
// Default to 30 days rather than 7. The old 7-day default made older rows look as if pagination was
// missing even though they were simply outside the active date filter.
$from   = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to     = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$dest   = trim($_GET['dest'] ?? '');
$text   = trim($_GET['q'] ?? '');
$userId = is_admin() ? (int)($_GET['user_id'] ?? 0) : (int)$me['id'];

$where  = ['m.sent_at >= ?', 'm.sent_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
$memberIdsForExport = [];   // populated for non-admins below; unused on the admin path
if (is_admin() && $userId) {
    $where[] = 'm.sender_user_id = ?';
    $params[] = $userId;
} elseif (!is_admin()) {
    $orgId = $me['organization_id'] ?? null;
    $memberIds = $orgId ? organization_member_user_ids((int)$orgId) : [];
    if (!$memberIds) {
        $memberIds = [(int)$me['id']];
    }
    $memberIdsForExport = $memberIds;
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $where[] = "m.sender_user_id IN ({$placeholders})";
    array_push($params, ...$memberIds);
}
if ($status !== '' && in_array($status, ['pending','sent','send_failed','delivered','failed'], true)) {
    $where[] = 'm.status = ?'; $params[] = $status;
}
if ($dest !== '') { $where[] = 'm.destination LIKE ?'; $params[] = '%' . preg_replace('/\D/', '', $dest) . '%'; }
if ($text !== '') { $where[] = 'm.content LIKE ?';     $params[] = '%' . $text . '%'; }
$W = implode(' AND ', $where);

/* ---------- Summary ---------- */
$reportOrgId  = !is_admin() ? (int)($me['organization_id'] ?? 0) ?: null : null;
$reportUserId = !is_admin() && !$reportOrgId ? (int)$me['id'] : null;
$S = Metrics::time('reports.summary', fn() => report_canonical_status_totals($W, $params, $reportOrgId, $reportUserId), ['source' => 'reports']);
$cnt = (int)$S['total'];

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    if (!is_admin()) {
        require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::REPORTS_ADVANCED);
    }

    if ($cnt <= 5000) {
        $st = backend_outbound_export_rows($W, $params, 100000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ellsms-report-' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['شناسه','کاربر','خط ارسال','گیرنده','متن پیام','تعداد پارت','وضعیت','شناسه‌ی گیت‌وی',
                       'مرجع اپراتور','وضعیت خام درگاه','تعداد تلاش استعلام','کد خطا','زمان ارسال','آخرین استعلام','زمان تحویل']);

        $buffer = [];
        $flush = static function (array $batch) use ($out, $reportOrgId, $reportUserId): void {
            if ($batch === []) {
                return;
            }
            $delivery = report_delivery_lookup_by_destination($batch, $reportOrgId, $reportUserId);

            foreach ($batch as $r) {
                $d = $delivery[(string)$r['destination']] ?? null;
                $canonical = report_canonical_status($d['delivery_status'] ?? null, (string)$r['status']);
                fputcsv($out, [
                    $r['id'], $r['username'], $r['originator'], $r['destination'], $r['content'],
                    sms_parts((string)$r['content']),
                    $canonical['status'],
                    $r['reference_id'] ?? '',
                    $d !== null && $d['provider_message_id'] !== null ? "\t" . (string)$d['provider_message_id'] : '',
                    $d !== null ? (string)($d['provider_status'] ?? '') : '',
                    $d !== null ? (string)(int)$d['delivery_attempts'] : '',
                    $r['error_code'],
                    report_local_mysql_time((string)$r['sent_at']),
                    $d !== null ? report_local_mysql_time((string)($d['delivery_checked_at'] ?? '')) : '',
                    $d !== null && !empty($d['delivered_at'])
                        ? report_local_mysql_time((string)$d['delivered_at'])
                        : report_local_mysql_time((string)($r['delivered_at'] ?? '')),
                ]);
            }
        };

        while ($r = $st->fetch()) {
            $buffer[] = $r;
            if (count($buffer) >= 500) {
                $flush($buffer);
                $buffer = [];
            }
        }
        $flush($buffer);
        exit;
    }

    $exportId = report_export_queue(
        (int)($me['organization_id'] ?? 0),
        (int)$me['id'],
        [
            'from'   => $from,
            'to'     => $to,
            'status' => $status,
            'dest'   => $dest,
            'q'      => $text,
            'is_admin'   => is_admin(),
            'user_id'    => is_admin() ? $userId : 0,
            'member_ids' => is_admin() ? [] : $memberIdsForExport,
        ],
        'ellsms-report-' . $from . '_' . $to . '.csv'
    );

    flash('info', 'این محدوده بیش از ۵۰۰۰ پیام دارد؛ خروجی در پس‌زمینه آماده می‌شود. از صفحه «خروجی‌های گزارش» دانلود کنید.');
    redirect('/reports/exports?queued=' . $exportId);
}

/* ---------- Paged rows (keyset/cursor pagination; OFFSET never used on unbounded sets) ---------- */
$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [25, 50, 100, 200], true)) {
    $per = 50;
}

$beforeId = (isset($_GET['before_id']) && $_GET['before_id'] !== '') ? (int)$_GET['before_id'] : null;
$afterId  = (isset($_GET['after_id'])  && $_GET['after_id']  !== '') ? (int)$_GET['after_id']  : null;
$cursor = null;
if ($beforeId !== null && $beforeId > 0) {
    $cursor = ['before_id' => $beforeId];
} elseif ($afterId !== null && $afterId > 0) {
    $cursor = ['after_id' => $afterId];
}

$page = max(1, (int)($_GET['page'] ?? 1));
if ($cursor === null) {
    $page = 1;
}

$fetched = Metrics::time(
    'reports.rows',
    fn() => backend_outbound_rows($W, $params, $per + 1, $cursor),
    ['source' => 'reports', 'per_page' => $per]
);
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;

if ($cursor !== null && isset($cursor['after_id'])) {
    $rows = array_reverse($rows);
}

$ids          = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBeforeId = $ids ? min($ids) : null;
$prevAfterId  = $ids ? max($ids) : null;

// Keyset pagination stays O(page-size): no deep OFFSET and no full-history fetch. `hasMore` is based
// on one extra row. Once the user has moved off page one, a "newer" direction always exists.
$hasNext = $rows !== [] && ($cursor === null || isset($cursor['before_id'])) ? $hasMore : true;
$hasPrev = $rows !== [] && $cursor !== null && (isset($cursor['after_id']) ? $hasMore : true);
$totalPages = max(1, (int)ceil($cnt / max(1, $per)));

/* ---------- Delivery lifecycle enrichment ---------- */
$deliveryByDest = report_delivery_lookup_by_destination($rows, $reportOrgId, $reportUserId);

$operatorNames = [];
if ($deliveryByDest) {
    $operatorNames = report_resolve_names([], [], array_column($deliveryByDest, 'operator_id'))['operators'];
}

$users = is_admin() ? backend_list_users_summary() : [];

$statusFa = ['sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','send_failed'=>'ناموفق','failed'=>'ناموفق','pending'=>'در انتظار'];
$qs = fn(array $extra = []) => http_build_query(array_filter(array_merge($_GET, $extra), static fn($v) => $v !== null && $v !== ''));
require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">تعداد پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($cnt)) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['ok'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">تحویل تأییدشده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['delivered'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (['sent','delivered','send_failed','failed','pending'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($statusFa[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (is_admin()): ?>
    <label>کاربر
      <select name="user_id">
        <option value="0">همه‌ی کاربران</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>شماره گیرنده <input type="text" name="dest" value="<?= e($dest) ?>" placeholder="۹۸۹۱…" class="ltr"></label>
    <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
    <label>تعداد در صفحه
      <select name="per_page">
        <?php foreach ([25,50,100,200] as $size): ?>
          <option value="<?= $size ?>" <?= $per === $size ? 'selected' : '' ?>><?= to_persian_digits((string)$size) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn btn-primary">اعمال فیلتر</button>
    <a class="btn" href="/messages/reports?<?= e($qs(['export' => 1, 'before_id' => null, 'after_id' => null, 'page' => null])) ?>">خروجی CSV</a>
    <a class="btn btn-ghost" href="/reports/exports">خروجی‌های آماده‌شده</a>
  </form>

  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
      <th>خط ارسال</th><th>گیرنده</th><th>اپراتور</th><th>متن پیام</th><th>پارت</th><th>وضعیت</th><th>زمان ارسال</th><th>زمان تحویل</th><th>عملیات</th>
    </tr>
    <?php foreach ($rows as $m):
      $d = $deliveryByDest[(string)$m['destination']] ?? null;
      $canonical = report_canonical_status($d['delivery_status'] ?? null, (string)$m['status']);
      $statusLabel = $canonical['label'];
      $statusClass = $canonical['class'];
      $parts = sms_parts((string)$m['content']);
    ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$m['id']) ?></td>
        <?php if (is_admin()): ?><td><?= e($m['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['originator']) ?></td>
        <td class="msisdn"><?= e($m['destination']) ?></td>
        <td><?= e($d !== null ? ($operatorNames[(int)($d['operator_id'] ?? 0)] ?? '—') : '—') ?></td>
        <td class="msg-preview" title="<?= e($m['content'] . ($m['error_code'] !== null ? "\n\nکد خطا: " . $m['error_code'] : '')) ?>">
          <?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?>
        </td>
        <td class="num"><?= to_persian_digits((string)$parts) ?></td>
        <td><span class="badge badge-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
        <td class="num"><?= report_jdate((string)$m['sent_at']) ?></td>
        <td class="num"><?= $d !== null && !empty($d['delivered_at'])
              ? report_jdate((string)$d['delivered_at'])
              : (!empty($m['delivered_at']) ? report_jdate((string)$m['delivered_at']) : '—') ?></td>
        <td><?php if ($d !== null): ?>
              <a class="btn btn-sm" href="/messages/detail?attempt=<?= (int)$d['id'] ?>">مشاهده</a>
            <?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= is_admin() ? 11 : 10 ?>" class="empty">هیچ پیامی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
  </table>
  </div>

  <div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px">
    <?php if ($hasPrev): ?>
      <a class="btn btn-sm" href="?<?= e($qs(['before_id' => null, 'after_id' => $prevAfterId, 'page' => max(1, $page - 1)])) ?>">→ جدیدتر</a>
    <?php else: ?>
      <span class="btn btn-sm btn-ghost" style="opacity:.45">→ جدیدتر</span>
    <?php endif; ?>

    <span class="btn btn-sm btn-ghost">
      صفحه <?= to_persian_digits(number_format($page)) ?> از <?= to_persian_digits(number_format($totalPages)) ?>
      · <?= to_persian_digits(number_format(count($rows))) ?> ردیف
    </span>

    <?php if ($hasNext): ?>
      <a class="btn btn-sm" href="?<?= e($qs(['after_id' => null, 'before_id' => $nextBeforeId, 'page' => $page + 1])) ?>">قدیمی‌تر ←</a>
    <?php else: ?>
      <span class="btn btn-sm btn-ghost" style="opacity:.45">قدیمی‌تر ←</span>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
