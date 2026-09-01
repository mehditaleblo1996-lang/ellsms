<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reporting/GradualScheduleView.php';
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

/* ---------------- Detailed direct/API messages ---------------- */
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

$ds = db()->prepare("SELECT COUNT(*) total, SUM(($directCanonical) IN ('sent','delivered')) ok_count, SUM(($directCanonical)='failed') failed_count FROM outbound_message m WHERE $DW");
$ds->execute($dp); $D = $ds->fetch() ?: [];

/* ---------------- Bulk jobs: aggregate only, never fetch 850k rows ---------------- */
$bw = ['bj.created_at >= ?', 'bj.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$bp = [$from, $to];
if (is_admin() && $userId > 0) {
    $bw[]='bj.user_id = ?'; $bp[]=$userId;
} elseif (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    if ($orgId > 0) { $bw[]='bj.organization_id = ?'; $bp[]=$orgId; }
    else { $bw[]='bj.user_id = ?'; $bp[]=(int)$me['id']; }
}
if ($senderDigits !== '') { $bw[]='bj.originator LIKE ?'; $bp[]='%'.$senderDigits.'%'; }
$BW = implode(' AND ', $bw);

$bulkSummarySt = db()->prepare("SELECT COALESCE(SUM(bj.total_rows),0) total,
                                      COALESCE(SUM(bj.sent_rows),0) sent_rows,
                                      COALESCE(SUM(bj.failed_rows),0) failed_rows
                               FROM ellsms_bulk_jobs bj WHERE $BW");
$bulkSummarySt->execute($bp);
$B = $bulkSummarySt->fetch() ?: [];

$bulkRowsSt = db()->prepare("SELECT bj.id,bj.user_id,bj.type,bj.title,bj.originator,bj.status,bj.total_rows,bj.sent_rows,bj.failed_rows,
                                   bj.throttle_count,bj.throttle_minutes,bj.last_throttle_at,bj.created_at,
                                   COALESCE(u.username, CONCAT('#',bj.user_id)) username
                            FROM ellsms_bulk_jobs bj
                            LEFT JOIN user_ u ON u.id=bj.user_id
                            WHERE $BW
                            ORDER BY bj.id DESC
                            LIMIT 50");
$bulkRowsSt->execute($bp);
$bulkJobs = $bulkRowsSt->fetchAll();

/* ---------------- Schedule definitions: complete overview ---------------- */
$sw = ['s.run_at >= ?', 's.run_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$sp = [$from, $to];
if (is_admin() && $userId > 0) {
    $sw[]='s.user_id=?'; $sp[]=$userId;
} elseif (!is_admin()) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $sw[]="s.user_id IN ($ph)"; array_push($sp, ...$memberIds);
}
if ($senderDigits !== '') { $sw[]='s.originator LIKE ?'; $sp[]='%'.$senderDigits.'%'; }
if ($text !== '') { $sw[]='s.content LIKE ?'; $sp[]='%'.$text.'%'; }
$SW = implode(' AND ', $sw);

$scheduleCountSt = db()->prepare("SELECT COUNT(*) FROM ellsms_schedule s WHERE $SW");
$scheduleCountSt->execute($sp);
$scheduleCount = (int)$scheduleCountSt->fetchColumn();

$scheduleSt = db()->prepare("SELECT s.id,s.user_id,s.title,s.originator,s.destinations,s.content,s.run_at,s.repeat_type,s.status,s.run_count,s.last_run_at,s.last_result,
                                    COALESCE(u.username, CONCAT('#',s.user_id)) username
                             FROM ellsms_schedule s
                             LEFT JOIN user_ u ON u.id=s.user_id
                             WHERE $SW
                             ORDER BY s.run_at DESC,s.id DESC
                             LIMIT 100");
$scheduleSt->execute($sp);
$schedules = $scheduleSt->fetchAll();

$S = [
    'total'=>(int)($D['total']??0)+(int)($B['total']??0),
    'ok'=>(int)($D['ok_count']??0)+(int)($B['sent_rows']??0),
    'failed'=>(int)($D['failed_count']??0)+(int)($B['failed_rows']??0),
    'scheduled'=>$scheduleCount,
];

/* ---------------- Keyset pagination for direct details ---------------- */
$cursorTime = trim((string)($_GET['before_time'] ?? ''));
$cursorId = (int)($_GET['before_id'] ?? 0);
$cursorSql = '';
$dpRows = $dp;
if ($cursorTime !== '' && $cursorId > 0) {
    $cursorSql = " AND (m.sent_at < ? OR (m.sent_at = ? AND m.id < ?))";
    array_push($dpRows, $cursorTime, $cursorTime, $cursorId);
}
$limit = $per + 1;
$directSql = "SELECT m.id,m.sent_at sort_time,m.sent_at,m.delivered_at,m.sender_user_id user_id,
                     COALESCE(u.username, CONCAT('#',m.sender_user_id)) username,m.originator,m.destination,m.content,$directCanonical canonical_status
              FROM outbound_message m
              LEFT JOIN user_ u ON u.id=m.sender_user_id
              WHERE $DW $cursorSql
              ORDER BY m.sent_at DESC,m.id DESC
              LIMIT $limit";
$directSt = db()->prepare($directSql);
$directSt->execute($dpRows);
$directRows = $directSt->fetchAll();
$hasNext = count($directRows) > $per;
$rows = array_slice($directRows, 0, $per);
$last = $rows ? $rows[count($rows)-1] : null;

$users = is_admin() ? backend_list_users_summary() : [];
$qs = fn(array $extra=[]) => http_build_query(array_filter(array_merge($_GET,$extra), static fn($v)=>$v!==null && $v!==''));
$bulkStatusFa = ['pending'=>'در صف','processing'=>'در حال ارسال','done'=>'انجام‌شده','cancelled'=>'لغوشده','staged'=>'منتظر تأیید'];
$scheduleStatusFa = ['active'=>'فعال','processing'=>'در حال ارسال','done'=>'انجام‌شده','cancelled'=>'لغوشده'];
$repeatFa = ['none'=>'یک‌بار','daily'=>'روزانه','weekly'=>'هفتگی','monthly'=>'ماهانه'];
require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">تعداد کل پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($S['total'])) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده</div><div class="stat-value"><?= to_persian_digits(number_format($S['ok'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($S['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">زمان‌بندی‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($S['scheduled'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
<form method="get" class="toolbar">
  <label>از تاریخ <?= jalali_date_select('from',$from) ?></label>
  <label>تا تاریخ <?= jalali_date_select('to',$to) ?></label>
  <label>وضعیت پیام <select name="status"><option value="">همه</option><?php foreach(['sent','delivered','failed','pending'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= e(report_v2_status_label($s)) ?></option><?php endforeach; ?></select></label>
  <?php if(is_admin()): ?><label>کاربر <select name="user_id"><option value="0">همه‌ی کاربران</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId===(int)$u['id']?'selected':'' ?>><?= e($u['username']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label>شماره فرستنده <input type="text" name="sender" value="<?= e($sender) ?>" placeholder="مثلاً 500045" class="ltr"></label>
  <label>شماره گیرنده <input type="text" name="dest" value="<?= e($dest) ?>" placeholder="9891…" class="ltr"></label>
  <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
  <label>تعداد در صفحه <select name="per_page"><?php foreach([25,50,100,200] as $n): ?><option value="<?= $n ?>" <?= $per===$n?'selected':'' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select></label>
  <button class="btn btn-primary">اعمال فیلتر</button>
</form>
</div>

<div class="card" style="margin-top:18px">
  <h2>ارسال‌های حجیم / نظیر به نظیر</h2>
  <p class="hint">هر Job فقط یک ردیف نمایش داده می‌شود؛ حتی اگر ۸۵۰هزار گیرنده داشته باشد. برای دیدن تک‌تک شماره‌ها «جزئیات» را بزنید.</p>
  <div class="table-wrap"><table>
    <tr><th>Job</th><th>نوع</th><?php if(is_admin()): ?><th>کاربر</th><?php endif; ?><th>عنوان</th><th>خط</th><th>کل</th><th>ارسال‌شده</th><th>ناموفق</th><th>باقی‌مانده</th><th>وضعیت</th><th>تنظیم تدریجی</th><th>تاریخ</th><th></th></tr>
    <?php foreach($bulkJobs as $j): $remaining=max(0,(int)$j['total_rows']-(int)$j['sent_rows']-(int)$j['failed_rows']); ?>
      <tr>
        <td class="num">#<?= to_persian_digits((string)$j['id']) ?></td>
        <td><?= e(report_bulk_type_label($j)) ?></td>
        <?php if(is_admin()): ?><td><?= e((string)$j['username']) ?></td><?php endif; ?>
        <td><?= e((string)$j['title']) ?></td>
        <td class="msisdn"><?= e((string)$j['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['total_rows'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['sent_rows'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['failed_rows'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format($remaining)) ?></td>
        <td><span class="badge badge-<?= e((string)$j['status']) ?>"><?= e($bulkStatusFa[(string)$j['status']] ?? (string)$j['status']) ?></span></td>
        <td class="num"><?php if(!empty($j['throttle_count'])): ?><?= to_persian_digits(number_format((int)$j['throttle_count'])) ?> هر <?= to_persian_digits((string)(int)$j['throttle_minutes']) ?> دقیقه<?php else: ?>—<?php endif; ?></td>
        <td class="num"><?= report_v2_jdate((string)$j['created_at']) ?></td>
        <td><a class="btn btn-sm" href="/messages/bulk-jobs?id=<?= (int)$j['id'] ?>">جزئیات</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$bulkJobs): ?><tr><td colspan="13" class="empty">ارسال حجیمی در این بازه وجود ندارد.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="card" style="margin-top:18px">
  <h2>زمان‌بندی‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>#</th><?php if(is_admin()): ?><th>کاربر</th><?php endif; ?><th>عنوان</th><th>زمان اجرا</th><th>تکرار</th><th>خط</th><th>گیرندگان</th><th>متن</th><th>وضعیت</th><th>تعداد اجرا</th><th>آخرین اجرا</th><th>آخرین نتیجه</th></tr>
    <?php foreach($schedules as $s): $sd=json_decode((string)$s['destinations'],true) ?: []; ?>
      <tr>
        <td class="num">#<?= to_persian_digits((string)$s['id']) ?></td>
        <?php if(is_admin()): ?><td><?= e((string)$s['username']) ?></td><?php endif; ?>
        <td><?= e((string)$s['title']) ?></td>
        <td class="num"><?= jdate((string)$s['run_at']) ?></td>
        <td><?= e($repeatFa[(string)$s['repeat_type']] ?? (string)$s['repeat_type']) ?></td>
        <td class="msisdn"><?= e((string)$s['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format(count($sd))) ?></td>
        <td class="msg-preview" title="<?= e((string)$s['content']) ?>"><?= e(mb_strimwidth((string)$s['content'],0,60,'…')) ?></td>
        <td><span class="badge badge-<?= e((string)$s['status']) ?>"><?= e($scheduleStatusFa[(string)$s['status']] ?? (string)$s['status']) ?></span></td>
        <td class="num"><?= to_persian_digits((string)(int)$s['run_count']) ?></td>
        <td class="num"><?= !empty($s['last_run_at']) ? jdate((string)$s['last_run_at']) : '—' ?></td>
        <td class="msg-preview" title="<?= e((string)$s['last_result']) ?>"><?= e(mb_strimwidth((string)$s['last_result'],0,45,'…')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$schedules): ?><tr><td colspan="12" class="empty">زمان‌بندی‌ای در این بازه وجود ندارد.</td></tr><?php endif; ?>
  </table></div>
  <div style="margin-top:10px"><a class="btn btn-sm" href="/schedules.php">مشاهده زمان‌بندی‌ها و مراحل تدریجی</a></div>
</div>

<div class="card" style="margin-top:18px">
<h2>پیام‌های عادی / API</h2>
<div class="table-wrap"><table>
<tr><th>#</th><?php if(is_admin()): ?><th>کاربر</th><?php endif; ?><th>خط ارسال</th><th>گیرنده</th><th>متن پیام</th><th>پارت</th><th>وضعیت</th><th>زمان ارسال</th><th>زمان تحویل</th></tr>
<?php foreach($rows as $r): $cs=(string)$r['canonical_status']; ?>
<tr>
<td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
<?php if(is_admin()): ?><td><?= e((string)$r['username']) ?></td><?php endif; ?>
<td class="msisdn"><?= e((string)$r['originator']) ?></td>
<td class="msisdn"><?= e((string)$r['destination']) ?></td>
<td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'],0,60,'…')) ?></td>
<td class="num"><?= to_persian_digits((string)sms_parts((string)$r['content'])) ?></td>
<td><span class="badge badge-<?= e(report_v2_status_class($cs)) ?>"><?= e(report_v2_status_label($cs)) ?></span></td>
<td class="num"><?= report_v2_jdate((string)$r['sent_at']) ?></td>
<td class="num"><?= !empty($r['delivered_at']) ? report_v2_jdate((string)$r['delivered_at']) : '—' ?></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?= is_admin()?9:8 ?>" class="empty">هیچ پیام عادی/API با این فیلترها یافت نشد.</td></tr><?php endif; ?>
</table></div>
<div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px">
  <a class="btn btn-sm" href="?<?= e($qs(['before_time'=>null,'before_id'=>null])) ?>">↻ جدیدترین</a>
  <span class="btn btn-sm btn-ghost"><?= to_persian_digits(number_format(count($rows))) ?> ردیف</span>
  <?php if($hasNext && $last): ?><a class="btn btn-sm" href="?<?= e($qs(['before_time'=>$last['sort_time'],'before_id'=>$last['id']])) ?>">قدیمی‌تر ←</a><?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
