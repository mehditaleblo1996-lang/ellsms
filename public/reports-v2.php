<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'گزارش ارسال';
$active = 'reports';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

function report_v2_local_time(?string $value): string {
    if ($value === null || trim($value) === '') return '';
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $value;
    }
}
function report_v2_jdate(?string $value): string {
    $v = report_v2_local_time($value);
    return $v === '' ? '' : jdate($v);
}
function report_v2_status_label(string $s): string {
    return ['sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','failed'=>'ناموفق','pending'=>'در انتظار'][$s] ?? $s;
}
function report_v2_status_class(string $s): string {
    return ['sent'=>'success','delivered'=>'success','failed'=>'error','pending'=>'pending'][$s] ?? 'pending';
}

$from   = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to     = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
$status = trim((string)($_GET['status'] ?? ''));
$dest   = trim((string)($_GET['dest'] ?? ''));
$sender = trim((string)($_GET['sender'] ?? ''));
$text   = trim((string)($_GET['q'] ?? ''));
$userId = is_admin() ? (int)($_GET['user_id'] ?? 0) : 0;
$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [25,50,100,200], true)) $per = 50;

$destDigits = preg_replace('/\D/', '', $dest) ?? '';
$senderDigits = preg_replace('/\D/', '', $sender) ?? '';
$memberIds = [];
if (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    $memberIds = $orgId > 0 ? organization_member_user_ids($orgId) : [];
    if (!$memberIds) $memberIds = [(int)$me['id']];
}

$dw = ['m.sent_at >= ?', 'm.sent_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$dp = [$from, $to];
if (is_admin() && $userId > 0) {
    $dw[] = 'm.sender_user_id = ?'; $dp[] = $userId;
} elseif (!is_admin()) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $dw[] = "m.sender_user_id IN ($ph)"; array_push($dp, ...$memberIds);
}
if ($destDigits !== '') { $dw[]='m.destination LIKE ?'; $dp[]='%'.$destDigits.'%'; }
if ($senderDigits !== '') { $dw[]='m.originator LIKE ?'; $dp[]='%'.$senderDigits.'%'; }
if ($text !== '') { $dw[]='m.content LIKE ?'; $dp[]='%'.$text.'%'; }
$directCanonical = "CASE WHEN m.status='delivered' THEN 'delivered' WHEN m.status IN ('send_failed','failed') THEN 'failed' WHEN m.status='sent' THEN 'sent' ELSE 'pending' END";
if (in_array($status, ['pending','sent','delivered','failed'], true)) { $dw[]="$directCanonical = ?"; $dp[]=$status; }
$DW = implode(' AND ', $dw);

$bw = ['bi.created_at >= ?', 'bi.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$bp = [$from, $to];
if (is_admin() && $userId > 0) {
    $bw[]='bj.user_id = ?'; $bp[]=$userId;
} elseif (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    if ($orgId > 0) { $bw[]='bj.organization_id = ?'; $bp[]=$orgId; }
    else { $bw[]='bj.user_id = ?'; $bp[]=(int)$me['id']; }
}
if ($destDigits !== '') { $bw[]='bi.mobile LIKE ?'; $bp[]='%'.$destDigits.'%'; }
if ($senderDigits !== '') { $bw[]='bj.originator LIKE ?'; $bp[]='%'.$senderDigits.'%'; }
if ($text !== '') { $bw[]='bi.content LIKE ?'; $bp[]='%'.$text.'%'; }
$bulkCanonical = "CASE WHEN bi.delivery_status='delivered' THEN 'delivered' WHEN bi.delivery_status IN ('failed','rejected','expired') OR bi.status IN ('failed','cancelled') THEN 'failed' WHEN bi.status='sent' THEN 'sent' ELSE 'pending' END";
if (in_array($status, ['pending','sent','delivered','failed'], true)) { $bw[]="$bulkCanonical = ?"; $bp[]=$status; }
$BW = implode(' AND ', $bw);

// Aggregate each physical source separately. This avoids full-row scans in PHP and avoids any
// collation/type coupling between the legacy outbound table and the bulk tables.
$ds = db()->prepare("SELECT COUNT(*) total, SUM(($directCanonical) IN ('sent','delivered')) ok_count, SUM(($directCanonical)='delivered') delivered_count, SUM(($directCanonical)='failed') failed_count FROM outbound_message m WHERE $DW");
$ds->execute($dp); $D = $ds->fetch() ?: [];
$bs = db()->prepare("SELECT COUNT(*) total, SUM(($bulkCanonical) IN ('sent','delivered')) ok_count, SUM(($bulkCanonical)='delivered') delivered_count, SUM(($bulkCanonical)='failed') failed_count FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id=bi.job_id WHERE $BW");
$bs->execute($bp); $B = $bs->fetch() ?: [];
$S = [
    'total'=>(int)($D['total']??0)+(int)($B['total']??0),
    'ok'=>(int)($D['ok_count']??0)+(int)($B['ok_count']??0),
    'delivered'=>(int)($D['delivered_count']??0)+(int)($B['delivered_count']??0),
    'failed'=>(int)($D['failed_count']??0)+(int)($B['failed_count']??0),
];

// Unified keyset cursor. Fetch at most per+1 rows FROM EACH source, then merge only that bounded
// candidate set in PHP. This intentionally avoids SQL UNION: the two historical tables can have
// different collations and UNIONing their text columns can fail in production even though each query
// is valid on its own. Worst-case PHP merge is 402 rows, never the whole report history.
$cursorTime = trim((string)($_GET['before_time'] ?? ''));
$cursorSource = trim((string)($_GET['before_source'] ?? ''));
$cursorId = (int)($_GET['before_id'] ?? 0);
$dpRows = $dp;
$bpRows = $bp;
$directCursor = '';
$bulkCursor = '';
if ($cursorTime !== '' && in_array($cursorSource, ['direct','bulk'], true) && $cursorId > 0) {
    $directCursor = " AND (m.sent_at < ? OR (m.sent_at = ? AND ('direct' < ? OR ('direct' = ? AND m.id < ?))))";
    array_push($dpRows, $cursorTime, $cursorTime, $cursorSource, $cursorSource, $cursorId);
    $bulkCursor = " AND (bi.created_at < ? OR (bi.created_at = ? AND ('bulk' < ? OR ('bulk' = ? AND bi.id < ?))))";
    array_push($bpRows, $cursorTime, $cursorTime, $cursorSource, $cursorSource, $cursorId);
}

$limit = $per + 1;
$directSql = "SELECT m.id, 'direct' source, m.sent_at sort_time, m.sent_at, m.delivered_at,
                    m.sender_user_id user_id, COALESCE(u.username, CONCAT('#',m.sender_user_id)) username,
                    m.originator, m.destination, m.content, $directCanonical canonical_status,
                    NULL job_id, NULL provider_message_id, NULL delivery_attempts, NULL delivery_checked_at
             FROM outbound_message m
             LEFT JOIN users u ON u.id=m.sender_user_id
             WHERE $DW $directCursor
             ORDER BY m.sent_at DESC, m.id DESC
             LIMIT $limit";
$dst = db()->prepare($directSql);
$dst->execute($dpRows);
$directRows = $dst->fetchAll();

$bulkSql = "SELECT bi.id, 'bulk' source, bi.created_at sort_time, bi.created_at sent_at, NULL AS delivered_at,
                  bj.user_id, COALESCE(u.username, CONCAT('#',bj.user_id)) username,
                  bj.originator, bi.mobile destination, bi.content, $bulkCanonical canonical_status,
                  bi.job_id, bi.provider_message_id, bi.delivery_attempts, bi.delivery_checked_at
           FROM ellsms_bulk_items bi
           JOIN ellsms_bulk_jobs bj ON bj.id=bi.job_id
           LEFT JOIN users u ON u.id=bj.user_id
           WHERE $BW $bulkCursor
           ORDER BY bi.created_at DESC, bi.id DESC
           LIMIT $limit";
$bst = db()->prepare($bulkSql);
$bst->execute($bpRows);
$bulkRows = $bst->fetchAll();

$fetched = array_merge($directRows, $bulkRows);
usort($fetched, static function (array $a, array $b): int {
    $timeCmp = strcmp((string)$b['sort_time'], (string)$a['sort_time']);
    if ($timeCmp !== 0) return $timeCmp;
    $sourceCmp = strcmp((string)$b['source'], (string)$a['source']);
    if ($sourceCmp !== 0) return $sourceCmp;
    return (int)$b['id'] <=> (int)$a['id'];
});
$hasNext = count($fetched) > $per;
$rows = array_slice($fetched, 0, $per);
$last = $rows ? $rows[count($rows)-1] : null;

$users = is_admin() ? backend_list_users_summary() : [];
$qs = fn(array $extra=[]) => http_build_query(array_filter(array_merge($_GET,$extra), static fn($v)=>$v!==null && $v!==''));
require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">تعداد پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($S['total'])) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($S['ok'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($S['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">تحویل تأییدشده</div><div class="stat-value"><?= to_persian_digits(number_format($S['delivered'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
<form method="get" class="toolbar">
  <label>از تاریخ <?= jalali_date_select('from',$from) ?></label>
  <label>تا تاریخ <?= jalali_date_select('to',$to) ?></label>
  <label>وضعیت <select name="status"><option value="">همه</option><?php foreach(['sent','delivered','failed','pending'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= e(report_v2_status_label($s)) ?></option><?php endforeach; ?></select></label>
  <?php if(is_admin()): ?><label>کاربر <select name="user_id"><option value="0">همه‌ی کاربران</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId===(int)$u['id']?'selected':'' ?>><?= e($u['username']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label>شماره فرستنده <input type="text" name="sender" value="<?= e($sender) ?>" placeholder="مثلاً 500045" class="ltr"></label>
  <label>شماره گیرنده <input type="text" name="dest" value="<?= e($dest) ?>" placeholder="9891…" class="ltr"></label>
  <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
  <label>تعداد در صفحه <select name="per_page"><?php foreach([25,50,100,200] as $n): ?><option value="<?= $n ?>" <?= $per===$n?'selected':'' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select></label>
  <button class="btn btn-primary">اعمال فیلتر</button>
</form>

<div class="table-wrap"><table>
<tr><th>#</th><th>نوع</th><?php if(is_admin()): ?><th>کاربر</th><?php endif; ?><th>خط ارسال</th><th>گیرنده</th><th>متن پیام</th><th>پارت</th><th>وضعیت</th><th>زمان ارسال</th><th>زمان تحویل</th><th>Job</th></tr>
<?php foreach($rows as $r): $cs=(string)$r['canonical_status']; ?>
<tr>
<td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
<td><?= $r['source']==='bulk'?'حجیم':'عادی/API' ?></td>
<?php if(is_admin()): ?><td><?= e((string)$r['username']) ?></td><?php endif; ?>
<td class="msisdn"><?= e((string)$r['originator']) ?></td>
<td class="msisdn"><?= e((string)$r['destination']) ?></td>
<td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'],0,60,'…')) ?></td>
<td class="num"><?= to_persian_digits((string)sms_parts((string)$r['content'])) ?></td>
<td><span class="badge badge-<?= e(report_v2_status_class($cs)) ?>"><?= e(report_v2_status_label($cs)) ?></span></td>
<td class="num"><?= report_v2_jdate((string)$r['sent_at']) ?></td>
<td class="num"><?= !empty($r['delivered_at']) ? report_v2_jdate((string)$r['delivered_at']) : '—' ?></td>
<td class="num"><?= $r['job_id']!==null ? '#'.to_persian_digits((string)$r['job_id']) : '—' ?></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?= is_admin()?11:10 ?>" class="empty">هیچ پیامی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
</table></div>

<div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px">
  <a class="btn btn-sm" href="?<?= e($qs(['before_time'=>null,'before_source'=>null,'before_id'=>null])) ?>">↻ جدیدترین</a>
  <span class="btn btn-sm btn-ghost"><?= to_persian_digits(number_format(count($rows))) ?> ردیف</span>
  <?php if($hasNext && $last): ?><a class="btn btn-sm" href="?<?= e($qs(['before_time'=>$last['sort_time'],'before_source'=>$last['source'],'before_id'=>$last['id']])) ?>">قدیمی‌تر ←</a><?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
