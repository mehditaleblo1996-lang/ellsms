<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'مخاطبین';
$active = 'contacts';

if (!is_admin()) {
    require_permission(Permissions::CONTACTS_VIEW);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete' && impersonation_guard_post('contacts.delete')) {
        redirect('/contacts.php');
    }

    if (!is_admin()) {
        require_permission(Permissions::CONTACTS_MANAGE);
    }

    $myOrgId = $me['organization_id'] ?? null;

    if ($do === 'add') {
        $mobile = normalize_msisdn($_POST['mobile'] ?? '');
        if (!$mobile) {
            flash('error', 'شماره موبایل معتبر نیست.');
        } else {
            $slot = entitlement_with_resource_slot((int)$myOrgId, Limits::CONTACTS, static function (PDO $db) use ($me, $myOrgId) {
                $db->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
                   ->execute([$me['id'], $myOrgId, trim($_POST['name'] ?? ''), normalize_msisdn($_POST['mobile'] ?? ''), trim($_POST['group_name'] ?? '')]);
                return true;
            });
            flash($slot['ok'] ? 'success' : 'error', $slot['ok']
                ? 'مخاطب افزوده شد.'
                : 'به سقف تعداد مخاطبین پلن فعلی رسیده‌اید (' . to_persian_digits((string)$slot['limit']) . ' مخاطب). مخاطبین موجود شما دست‌نخورده باقی می‌مانند؛ برای افزودن مخاطب جدید پلن خود را ارتقا دهید.');
        }
    }

    if ($do === 'import') {
        $group = trim($_POST['group_name'] ?? '');
        $lines = preg_split('/\\R/u', $_POST['bulk'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $limit = organization_limit((int)$myOrgId, Limits::CONTACTS);
        $imported = 0;
        $skippedOverLimit = 0;
        db_transaction(function (PDO $db) use ($me, $myOrgId, $lines, $group, $limit, &$imported, &$skippedOverLimit): void {
            $db->prepare('SELECT id FROM ellsms_organizations WHERE id = ? FOR UPDATE')->execute([$myOrgId]);
            $current = $limit === null ? 0 : entitlement_current_resource_count((int)$myOrgId, Limits::CONTACTS, $db);
            $ins = $db->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)');
            foreach ($lines as $line) {
                [$a, $b] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
                $mobile = normalize_msisdn($a) ?? normalize_msisdn($b);
                $name   = normalize_msisdn($a) ? $b : $a;
                if (!$mobile) {
                    continue;
                }
                if ($limit !== null && ($current + $imported) >= $limit) {
                    $skippedOverLimit++;
                    continue;
                }
                $ins->execute([$me['id'], $myOrgId, $name, $mobile, $group]);
                $imported++;
            }
        });
        if ($skippedOverLimit > 0) {
            flash('error', to_persian_digits((string)$imported) . ' مخاطب وارد شد؛ ' . to_persian_digits((string)$skippedOverLimit)
                . ' مورد به دلیل رسیدن به سقف پلن وارد نشد. برای افزایش ظرفیت، پلن خود را ارتقا دهید.');
        } else {
            flash($imported ? 'success' : 'error', $imported ? to_persian_digits((string)$imported) . ' مخاطب وارد شد.' : 'هیچ شماره‌ی معتبری در متن پیدا نشد.');
        }
    }

    if ($do === 'delete') {
        db()->prepare('DELETE FROM ellsms_contacts WHERE id=? AND (organization_id = ? OR (organization_id IS NULL AND user_id = ?))')
           ->execute([(int)$_POST['id'], $myOrgId, $me['id']]);
        flash('info', 'مخاطب حذف شد.');
    }
    redirect('/contacts.php');
}

$myOrgId = $me['organization_id'] ?? null;
$g = trim($_GET['group'] ?? '');
$ownership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';

// Group chips are aggregate rows, not contacts themselves; this result is bounded by the number of
// distinct group names. The contact table below is always keyset-paged and never fetches the whole set.
$gr = db()->prepare("SELECT group_name, COUNT(*) c FROM ellsms_contacts WHERE {$ownership} AND group_name<>'' GROUP BY group_name ORDER BY group_name");
$gr->execute([$myOrgId, $me['id']]);
$groups = $gr->fetchAll();

$per = 100;
$beforeId = (isset($_GET['before_id']) && $_GET['before_id'] !== '') ? (int)$_GET['before_id'] : null;
$afterId  = (isset($_GET['after_id']) && $_GET['after_id'] !== '') ? (int)$_GET['after_id'] : null;
$where = $ownership;
$params = [$myOrgId, $me['id']];
if ($g !== '') {
    $where .= ' AND group_name = ?';
    $params[] = $g;
}
$order = 'DESC';
if ($beforeId !== null && $beforeId > 0) {
    $where .= ' AND id < ?';
    $params[] = $beforeId;
} elseif ($afterId !== null && $afterId > 0) {
    $where .= ' AND id > ?';
    $params[] = $afterId;
    $order = 'ASC';
}
$st = db()->prepare("SELECT id, name, mobile, group_name, created_at FROM ellsms_contacts WHERE {$where} ORDER BY id {$order} LIMIT " . ($per + 1));
$st->execute($params);
$fetched = $st->fetchAll();
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;
if ($afterId !== null && $afterId > 0) {
    $rows = array_reverse($rows);
}
$ids = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBeforeId = $ids ? min($ids) : null;
$prevAfterId = $ids ? max($ids) : null;
$hasPrev = $rows !== [] && ($beforeId !== null || $afterId !== null);
$hasNext = $rows !== [] && (($afterId !== null) || $hasMore);
$baseQuery = $g !== '' ? ['group' => $g] : [];

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>افزودن مخاطب</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add">
      <div class="form-row">
        <label>نام <input type="text" name="name"></label>
        <label>موبایل <input type="text" name="mobile" required placeholder="۰۹۱۲… یا ۹۸۹۱۲…" class="ltr"></label>
        <label>گروه <input type="text" name="group_name" list="grouplist" placeholder="مثلاً مشتریان"></label>
      </div>
      <button class="btn btn-primary">افزودن مخاطب</button>
    </form>
  </div>
  <div class="card">
    <h2>وارد کردن گروهی</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="import">
      <label>هر خط را جدا وارد کنید — <span class="hint" style="display:inline">شماره یا «نام، شماره»</span>
        <textarea name="bulk" placeholder="علی، ۰۹۱۲۱۲۳۴۵۶۷&#10;۰۹۳۵۱۲۳۴۵۶۷"></textarea>
      </label>
      <label>در گروه <input type="text" name="group_name" list="grouplist"></label>
      <button class="btn btn-primary">وارد کردن</button>
    </form>
  </div>
</div>

<datalist id="grouplist">
  <?php foreach ($groups as $x): ?><option value="<?= e($x['group_name']) ?>"><?php endforeach; ?>
</datalist>

<div class="card">
  <h2>مخاطبین<?= $g !== '' ? ' — گروه «' . e($g) . '»' : '' ?></h2>
  <p>
    <a class="btn btn-sm <?= $g === '' ? 'btn-primary' : '' ?>" href="/contacts.php">همه</a>
    <?php foreach ($groups as $x): ?>
      <a class="btn btn-sm <?= $g === $x['group_name'] ? 'btn-primary' : '' ?>" href="/contacts.php?group=<?= urlencode($x['group_name']) ?>">
        <?= e($x['group_name']) ?> (<?= to_persian_digits((string)$x['c']) ?>)
      </a>
    <?php endforeach; ?>
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>موبایل</th><th>گروه</th><th>تاریخ افزودن</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td class="msisdn"><?= e($c['mobile']) ?></td>
        <td><?= e($c['group_name']) ?></td>
        <td class="num"><?= jdate($c['created_at'], false) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('این مخاطب حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">هنوز مخاطبی وجود ندارد.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php if ($hasPrev || $hasNext): ?>
  <div class="pagination">
    <?php if ($hasPrev && $prevAfterId): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($baseQuery, ['after_id' => $prevAfterId]))) ?>">→ جدیدتر</a><?php endif; ?>
    <?php if ($hasNext && $nextBeforeId): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($baseQuery, ['before_id' => $nextBeforeId]))) ?>">قدیمی‌تر ←</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
