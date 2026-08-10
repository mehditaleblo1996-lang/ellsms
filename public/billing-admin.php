<?php
/**
 * ELLSMS — PLATFORM-ADMIN subscription administration (Phase 13, STEP 36).
 *
 * Deliberately a separate page from public/billing.php, gated on require_admin() (install-wide
 * platform administration) and NEVER on an organization permission — Invariant O: an organization
 * owner manages their OWN subscription on billing.php; assigning an arbitrary plan to an arbitrary
 * organization, suspending one, or granting a trial is platform authority that no customer role can
 * ever hold, no matter what plan they are on.
 *
 * Every action here is audited. None of them touch the financial ledger directly (STEP 36's "do not
 * bypass financial ledger for manual credit/payment changes") — an admin-assigned plan creates no
 * billing record and moves no money; it is an operational override, recorded as one.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'مدیریت اشتراک‌ها';
$active = 'billing_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $targetOrgId = (int)($_POST['organization_id'] ?? 0);

    // The target must be a real organization — a crafted id must not create phantom subscription
    // rows (the FK would reject it anyway; this turns that into a clean message).
    $orgSt = db()->prepare('SELECT id, name FROM ellsms_organizations WHERE id = ?');
    $orgSt->execute([$targetOrgId]);
    $targetOrg = $orgSt->fetch();

    if (!$targetOrg) {
        flash('error', 'سازمان انتخابی پیدا نشد.');
    } elseif ($do === 'assign_plan') {
        // Platform admin may assign ANY plan, including non-public ones (`legacy`) — that is
        // precisely the authority this page exists to provide, and why it can never be reachable
        // through an organization role.
        $planId = (int)($_POST['plan_id'] ?? 0);
        $plan = billing_plan_by_id($planId);
        if (!$plan) {
            flash('error', 'پلن انتخابی پیدا نشد.');
        } else {
            $current = subscription_for_organization($targetOrgId);
            $result = $current === null
                ? subscription_create($targetOrgId, $planId, 'active', (int)$me['id'], 'platform_admin')
                : subscription_change_plan($targetOrgId, $planId, (int)$me['id'], 'immediate', null, 'platform_admin');
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'پلن سازمان اعمال شد.' : 'اعمال پلن ممکن نشد: ' . e($result['reason'] ?? ''));
            audit((int)$me['id'], 'billing.admin.assign_plan', "org={$targetOrgId} plan={$plan['code']}");
        }
    } elseif ($do === 'suspend') {
        $result = subscription_transition($targetOrgId, 'suspended', 'suspended_by_admin', (int)$me['id'], null, 'platform admin action');
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'اشتراک سازمان معلق شد.' : 'تعلیق ممکن نشد: ' . e($result['reason'] ?? ''));
    } elseif ($do === 'reactivate') {
        $result = subscription_transition($targetOrgId, 'active', 'reactivated_by_admin', (int)$me['id'], null, 'platform admin action');
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'اشتراک سازمان فعال شد.' : 'فعال‌سازی ممکن نشد: ' . e($result['reason'] ?? ''));
    } elseif ($do === 'grant_trial') {
        // The override flag is what lets a platform admin re-grant a trial an organization has
        // already used — the one documented exception to "one trial per organization" (STEP 30).
        $planId = (int)($_POST['plan_id'] ?? 0);
        $result = subscription_start_trial($targetOrgId, $planId, (int)$me['id'], true);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'دوره‌ی آزمایشی اعطا شد.' : 'اعطای دوره‌ی آزمایشی ممکن نشد: ' . e($result['reason'] ?? ''));
        audit((int)$me['id'], 'billing.admin.grant_trial', "org={$targetOrgId} plan_id={$planId}");
    }
    redirect('/billing-admin.php');
}

$organizations = db()->query(
    "SELECT o.id, o.name, o.status,
            s.status AS subscription_status, s.current_period_end, s.trial_ends_at, s.grace_ends_at,
            s.cancel_at_period_end, p.code AS plan_code
     FROM ellsms_organizations o
     LEFT JOIN ellsms_subscriptions s ON s.effective_organization_id = o.id
     LEFT JOIN ellsms_plans p ON p.id = s.plan_id
     ORDER BY o.id"
)->fetchAll();
$plans = billing_all_plans();
$statusFa = [
    'trialing' => 'آزمایشی', 'active' => 'فعال', 'past_due' => 'معوق',
    'grace' => 'ارفاقی', 'suspended' => 'معلق', 'cancelled' => 'لغوشده', 'expired' => 'منقضی',
];

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!billing_enabled()): ?>
<div class="card">
  <div class="flash flash-info">
    سیستم اشتراک غیرفعال است (<span class="ltr">BILLING_ENABLED=0</span>) — تنظیمات زیر ذخیره می‌شود ولی هیچ محدودیتی اعمال نمی‌گردد.
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>پلن‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>کد</th><th>نام</th><th>قیمت</th><th>دوره</th><th>وضعیت</th><th>عمومی</th><th>پیش‌فرض</th></tr>
    <?php foreach ($plans as $p): ?>
      <tr>
        <td class="ltr"><?= e($p['code']) ?></td>
        <td><?= e($p['name']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$p['price_amount'])) ?> <?= e($p['currency']) ?></td>
        <td><?= ['none' => '—', 'monthly' => 'ماهانه', 'yearly' => 'سالانه'][$p['billing_period']] ?></td>
        <td><?= $p['status'] === 'active' ? 'فعال' : 'بایگانی' ?></td>
        <td><?= $p['is_public'] ? 'بله' : 'خیر' ?></td>
        <td><?= $p['is_default'] ? 'بله' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="hint">پلن‌ها با <span class="ltr">make billing-backfill</span> ساخته می‌شوند و قیمت‌های اولیه صرفاً نمونه‌اند — پیش از فروش بازبینی کنید (<span class="ltr">docs/billing-operations.md</span>).</p>
</div>

<div class="card">
  <h2>سازمان‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>نام</th><th>پلن</th><th>وضعیت اشتراک</th><th>پایان دوره</th><th>عملیات</th></tr>
    <?php foreach ($organizations as $o): ?>
      <tr>
        <td class="num"><?= (int)$o['id'] ?></td>
        <td><?= e($o['name']) ?><?= $o['status'] !== 'active' ? ' <span class="hint">(' . e($o['status']) . ')</span>' : '' ?></td>
        <td class="ltr"><?= e($o['plan_code'] ?? '—') ?></td>
        <td><?= e($statusFa[$o['subscription_status']] ?? '—') ?><?= $o['cancel_at_period_end'] ? ' (لغو در پایان دوره)' : '' ?></td>
        <td><?= $o['current_period_end'] ? jdate($o['current_period_end'], false) : '—' ?></td>
        <td>
          <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="organization_id" value="<?= (int)$o['id'] ?>">
            <select name="plan_id" style="max-width:150px">
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int)$p['id'] ?>"<?= $p['code'] === $o['plan_code'] ? ' selected' : '' ?>><?= e($p['code']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm" name="do" value="assign_plan">اعمال پلن</button>
            <button class="btn btn-sm" name="do" value="grant_trial">آزمایشی</button>
            <?php if ($o['subscription_status'] === 'suspended'): ?>
              <button class="btn btn-sm btn-primary" name="do" value="reactivate">فعال‌سازی</button>
            <?php elseif ($o['subscription_status'] !== null): ?>
              <button class="btn btn-sm btn-danger" name="do" value="suspend" onclick="return confirm('اشتراک این سازمان معلق شود؟')">تعلیق</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$organizations): ?><tr><td colspan="6" class="empty">سازمانی وجود ندارد.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
