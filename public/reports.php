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

/* ---------- Filters ---------- */
$from   = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-6 day'));
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
    // Phase 6, STEP 25: scoped to every member of the ACTIVE organization, not just $me — an
    // organization may contain multiple users, and a report scoped to only "my own" sends would
    // hide org-mates' outbound history from a member who should legitimately see it. Falls back to
    // exactly the pre-Phase-6 single-user scope when there's no resolvable organization yet
    // (pre-tenant-backfill) — outbound_message is backend-owned (no organization_id column to
    // filter on directly, STEP 23), so this is expressed as "sender_user_id IN (org member ids)".
    $orgId = $me['organization_id'] ?? null;
    $memberIds = $orgId ? organization_member_user_ids((int)$orgId) : [];
    if (!$memberIds) {
        $memberIds = [(int)$me['id']];
    }
    // Captured for a queued export so the worker reproduces this exact tenant scope rather than
    // re-resolving org membership later, which may have changed by the time it runs.
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

/* ---------- Summary ----------
 * Counted from the SAME canonical status resolution as the list rows and CSV export
 * (report_canonical_status()), never a second raw-status-only aggregate that could disagree with
 * what the rows below actually show.
 */
$reportOrgId  = !is_admin() ? (int)($me['organization_id'] ?? 0) ?: null : null;
$reportUserId = !is_admin() && !$reportOrgId ? (int)$me['id'] : null;
$S = Metrics::time('reports.summary', fn() => report_canonical_status_totals($W, $params, $reportOrgId, $reportUserId), ['source' => 'reports']);
$cnt = (int)$S['total'];

/* ---------- CSV export (synchronous fallback for very small result sets only) ---------- */
if (isset($_GET['export'])) {
    // Phase 13 (STEP 14): the report ITSELF is available on every plan (taking basic send history
    // away would make the product unusable — see app/Support/Entitlements.php's docblock); the bulk
    // CSV EXPORT is the plan-gated "advanced reporting" capability. Platform admins keep their
    // existing unrestricted bypass (Invariant O).
    if (!is_admin()) {
        require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::REPORTS_ADVANCED);
    }

    // Large exports are prepared asynchronously by the export worker so the HTTP request never
    // holds a huge result set. Keep this synchronous path only for tiny ranges for backward
    // compatibility with any existing bookmarked export links.
    if ($cnt <= 5000) {
        $st = backend_outbound_export_rows($W, $params, 100000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ellsms-report-' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (Persian) correctly
        fputcsv($out, ['شناسه','کاربر','خط ارسال','گیرنده','متن پیام','تعداد پارت','وضعیت','شناسه‌ی گیت‌وی',
                       'مرجع اپراتور','وضعیت خام درگاه','تعداد تلاش استعلام','کد خطا','زمان ارسال','آخرین استعلام','زمان تحویل']);

        // Delivery lifecycle is looked up in BOUNDED CHUNKS as rows stream, so a 100k-row export neither
        // issues 100k queries nor loads every attempt into memory at once. Uses the SAME canonical
        // lookup/status resolution as the summary cards and the list rows above
        // (report_delivery_lookup_by_destination()/report_canonical_status()) — org/user-scoped and
        // degrade-safe — rather than a third, separately-maintained copy of this correlation.
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
                    // outbound_message is backend-owned and reference_id is optional across
                    // deployments (it is absent from the integration fixture), so it is read
                    // defensively — a missing optional column must not fatal an export.
                    $r['reference_id'] ?? '',
                    // B28/B9: a 19-digit provider reference is written with a leading tab so Excel keeps
                    // it as TEXT. Without this the cell becomes 4.47362E+18 and the reference is lost.
                    $d !== null && $d['provider_message_id'] !== null ? "\t" . (string)$d['provider_message_id'] : '',
                    $d !== null ? (string)($d['provider_status'] ?? '') : '',
                    $d !== null ? (string)(int)$d['delivery_attempts'] : '',
                    $r['error_code'],
                    $r['sent_at'],
                    $d !== null ? (string)($d['delivery_checked_at'] ?? '') : '',
                    $d !== null && !empty($d['delivered_at']) ? (string)$d['delivered_at'] : (string)($r['delivered_at'] ?? ''),
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

    // Too large to stream inside the request: queue a durable export job instead. The filters are
    // captured HERE, together with the tenant scope that applies to THIS requester right now, so the
    // worker reproduces exactly the rows this page would have shown. It re-compiles them through
    // report_export_filter_sql() — the same builder used above — rather than storing SQL.
    $exportId = report_export_queue(
        (int)($me['organization_id'] ?? 0),
        (int)$me['id'],
        [
            'from'   => $from,
            'to'     => $to,
            'status' => $status,
            'dest'   => $dest,
            'q'      => $text,
            // Admin-ness is recorded as a fact of the request. If this user's role changes before
            // the worker runs, the export must not retroactively widen or narrow.
            'is_admin'   => is_admin(),
            'user_id'    => is_admin() ? $userId : 0,
            'member_ids' => is_admin() ? [] : $memberIdsForExport,
        ],
        'ellsms-report-' . $from . '_' . $to . '.csv'
    );

    flash('info', 'این محدوده بیش از ۵۰۰۰ پیام دارد؛ خروجی در پس‌زمینه آماده می‌شود. از صفحه «خروجی‌های گزارش» دانلود کنید.');
    redirect('/report-exports.php?queued=' . $exportId);
}

/* ---------- Paged rows (keyset/cursor pagination; OFFSET never used on unbounded sets) ---------- */
$per = (int)($_GET['per_page'] ?? 50);
if ($per < 1) {
    $per = 1;
}
if ($per > 200) {
    $per = 200;
}

$beforeId = (isset($_GET['before_id']) && $_GET['before_id'] !== '') ? (int)$_GET['before_id'] : null;
$afterId  = (isset($_GET['after_id'])  && $_GET['after_id']  !== '') ? (int)$_GET['after_id']  : null;
$cursor = null;
if ($beforeId !== null && $beforeId > 0) {
    $cursor = ['before_id' => $beforeId];
} elseif ($afterId !== null && $afterId > 0) {
    $cursor = ['after_id' => $afterId];
}
// One extra row is requested purely to answer "is there another page?" without a COUNT(*) over the
// whole filtered set. It is trimmed before rendering and never displayed.
$fetched = Metrics::time(
    'reports.rows',
    fn() => backend_outbound_rows($W, $params, $per + 1, $cursor),
    ['source' => 'reports', 'per_page' => $per]
);
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;

// backend_outbound_rows() returns newest-first for a `before_id` (or first) page, but oldest-first
// for an `after_id` page -- it has to, because "the $per rows immediately NEWER than this id" is
// only expressible as ORDER BY id ASC. Flip it back so the table always reads newest-first.
if ($cursor !== null && isset($cursor['after_id'])) {
    $rows = array_reverse($rows);
}

$ids          = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBeforeId = $ids ? min($ids) : null;   // follow "older" from the oldest row on this page
$prevAfterId  = $ids ? max($ids) : null;   // follow "newer" from the newest row on this page

// Going older is offered when this page filled up; going newer only once the reader has actually
// moved off the first page, so the initial view shows a single "older" link rather than a dead one.
$hasNext = $rows !== [] && ($cursor === null || isset($cursor['before_id'])) ? $hasMore : true;
$hasPrev = $rows !== [] && $cursor !== null && (isset($cursor['after_id']) ? $hasMore : true);

/* ---------- Delivery lifecycle enrichment ----------
 * A send that went through a configured GATEWAY records its transport identity and delivery state in
 * ELLSMS's own ellsms_message_attempts (backend-owned outbound_message has no column for a provider
 * reference — Phase 8, Invariant E). Without this join the list can show "ارسال‌شده" forever for a
 * message the poller has since confirmed delivered, because the two records live in different tables.
 *
 * ONE query for the whole page, keyed by destination + day, rather than one per row: the two records
 * share no id, so destination and send date are what correlate them. A row with no gateway attempt
 * (the legacy transport) simply gets no extra columns, exactly as before.
 */
$deliveryByDest = report_delivery_lookup_by_destination($rows, $reportOrgId, $reportUserId);

// Operator names for the whole page in ONE query (B20 — no N+1).
$operatorNames = [];
if ($deliveryByDest) {
    $operatorNames = report_resolve_names([], [], array_column($deliveryByDest, 'operator_id'))['operators'];
}

$users = is_admin() ? backend_list_users_summary() : [];

$statusFa = ['sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','send_failed'=>'ناموفق','failed'=>'ناموفق','pending'=>'در انتظار'];
$qs = fn(array $extra = []) => http_build_query(array_merge($_GET, $extra));
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
    <button class="btn btn-primary">اعمال فیلتر</button>
    <a class="btn" href="/reports.php?<?= e($qs(['export' => 1])) ?>">خروجی CSV</a>
    <a class="btn btn-ghost" href="/report-exports.php">خروجی‌های آماده‌شده</a>
  </form>

  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
      <th>خط ارسال</th><th>گیرنده</th><th>اپراتور</th><th>متن پیام</th><th>پارت</th><th>وضعیت</th><th>زمان ارسال</th><th>زمان تحویل</th><th>عملیات</th>
    </tr>
    <?php foreach ($rows as $m):
      // The gateway attempt for this destination, when the send went through a configured gateway.
      // Its delivery_status is the AUTHORITATIVE one — it is what the status poller maintains.
      $d = $deliveryByDest[(string)$m['destination']] ?? null;
      $canonical = report_canonical_status($d['delivery_status'] ?? null, (string)$m['status']);
      $statusLabel = $canonical['label'];
      $statusClass = $canonical['class'];
      // Part count from the SAME engine pricing and cost preview use — never a second algorithm.
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
        <td class="num"><?= jdate($m['sent_at']) ?></td>
        <?php /* B25: only a real delivered_at is shown as a delivery time. delivery_checked_at is
                 "when we last asked" and belongs on the detail page, never in this column. */ ?>
        <td class="num"><?= $d !== null && !empty($d['delivered_at'])
              ? jdate($d['delivered_at'])
              : (!empty($m['delivered_at']) ? jdate($m['delivered_at']) : '—') ?></td>
        <td><?php if ($d !== null): ?>
              <a class="btn btn-sm" href="/message-detail.php?attempt=<?= (int)$d['id'] ?>">مشاهده</a>
            <?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= is_admin() ? 11 : 10 ?>" class="empty">هیچ پیامی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php /* Keyset pagination: links carry a cursor id, not an offset. A total page COUNT is
           deliberately not shown -- deriving one needs COUNT(*) over the whole filtered set, which
           is the very thing that stops being affordable at millions of rows. */ ?>
  <?php if ($hasPrev || $hasNext): ?>
  <div class="pagination">
    <?php if ($hasPrev): ?>
      <a class="btn btn-sm" href="?<?= e($qs(['before_id' => null, 'after_id' => $prevAfterId])) ?>">→ جدیدتر</a>
    <?php endif; ?>
    <span class="btn btn-sm btn-ghost"><?= to_persian_digits(number_format(count($rows))) ?> ردیف</span>
    <?php if ($hasNext): ?>
      <a class="btn btn-sm" href="?<?= e($qs(['after_id' => null, 'before_id' => $nextBeforeId])) ?>">قدیمی‌تر ←</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
