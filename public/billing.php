<?php
/**
 * ELLSMS — organization subscription & usage page (Phase 13, STEP 38).
 *
 * Shows the current plan, subscription state, period dates, and used/limit/remaining for every
 * limit — plus self-service upgrade / scheduled-downgrade / cancel actions.
 *
 * NOTHING here is an enforcement point (Invariant D — UI visibility is not authorization): every
 * action re-checks permission and re-derives price server-side, and every limit shown is rendered
 * from the same central service the actual gates use, never a second copy of the rule.
 */
require_once __DIR__ . '/../app/Payment/PaymentGateway.php';
$me = require_login();
$pageTitle = 'اشتراک و مصرف';
$active = 'billing';

$org = require_permission(Permissions::BILLING_VIEW);
$orgId = (int)$org['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Plan changes and cancellations are financial commitments made on the customer's behalf; the
    // page stays fully READABLE during support, which is the part that actually helps (STEP 27).
    if (impersonation_guard_post('billing.subscription')) {
        redirect('/billing.php');
    }
    // BILLING_MANAGE is owner-only by default (app/rbac.php) — an org admin may VIEW this page but
    // not commit the organization to a paid plan, mirroring the existing WALLET_ADJUST precedent.
    require_permission(Permissions::BILLING_MANAGE);
    $do = $_POST['do'] ?? '';

    if ($do === 'change_plan') {
        // The plan is resolved from its own id against the PUBLIC plan list only — a crafted
        // plan_id naming an internal/archived plan (e.g. `legacy`, or a hidden enterprise plan)
        // can never be self-assigned (STEP 51).
        $requestedPlanId = (int)($_POST['plan_id'] ?? 0);
        $plan = null;
        foreach (billing_public_plans() as $candidate) {
            if ((int)$candidate['id'] === $requestedPlanId) {
                $plan = $candidate;
                break;
            }
        }

        if (!$plan) {
            flash('error', 'پلن انتخابی معتبر نیست.');
        } elseif ((int)$plan['price_amount'] === 0) {
            // A free plan needs no payment — apply it directly. Moving DOWN to free is scheduled at
            // period end so the customer keeps what they already paid for (STEP 28).
            $current = subscription_for_organization($orgId);
            $isDowngrade = $current !== null && (int)$current['price_amount'] > 0;
            $result = $current === null
                ? subscription_create($orgId, (int)$plan['id'], 'active', (int)$me['id'], 'self_service')
                : subscription_change_plan($orgId, (int)$plan['id'], (int)$me['id'], $isDowngrade ? 'at_period_end' : 'immediate');
            flash($result['ok'] ? 'success' : 'error', $result['ok']
                ? (($result['reason'] ?? '') === 'scheduled'
                    ? 'تغییر پلن در پایان دوره‌ی جاری اعمال می‌شود — تا آن زمان امکانات فعلی شما دست‌نخورده باقی می‌ماند.'
                    : 'پلن سازمان به‌روزرسانی شد.')
                : 'تغییر پلن ممکن نشد.');
            redirect('/billing.php');
        } else {
            // Paid plan: the amount comes from the PLAN ROW, never from request input (STEP 31/51).
            $record = billing_record_create($orgId, $plan);
            $gateway = payment_gateway_name();
            db()->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,?, 'subscription', ?, ?, ?)")
               ->execute([$me['id'], $orgId, 0, $record['billing_record_id'], $record['amount'], $gateway]);
            $paymentId = (int)db()->lastInsertId();
            db()->prepare('UPDATE ellsms_billing_records SET payment_id = ? WHERE id = ?')->execute([$paymentId, $record['billing_record_id']]);

            $create = payment_gateway_create($gateway, $record['amount'], $paymentId, "اشتراک {$plan['name']} — ELLSMS", (string)($me['mobile'] ?? ''));
            if ($create['ok']) {
                db()->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
                // FIN-4: subscription-purchase invoice, item_type distinguishes it from a pure
                // renewal at the same plan (FIN-7 sets item_type='subscription_renewal' instead).
                billing_invoice_create($paymentId, $orgId, (int)$me['id'], 'subscription', [[
                    'item_type' => 'subscription_plan', 'reference_code' => $plan['code'],
                    'description' => "اشتراک {$plan['name']} ({$record['period_months']} ماه)", 'quantity' => 1, 'unit_price' => $record['amount'],
                ]]);
                audit((int)$me['id'], 'billing.subscription.payment_request', "org={$orgId} plan={$plan['code']} payment=#{$paymentId} gateway={$gateway}");
                redirect(payment_gateway_redirect_url($gateway, $create['authority']));
            }
            db()->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);
            db()->prepare("UPDATE ellsms_billing_records SET status='failed' WHERE id=?")->execute([$record['billing_record_id']]);
            flash('error', 'شروع پرداخت ممکن نشد: ' . e($create['message']));
        }
    } elseif ($do === 'cancel') {
        $result = subscription_cancel($orgId, (int)$me['id'], false);
        flash($result['ok'] ? 'info' : 'error', $result['ok']
            ? 'اشتراک شما در پایان دوره‌ی جاری لغو خواهد شد. تا آن زمان همه‌چیز به‌صورت عادی کار می‌کند و هیچ داده‌ای حذف نمی‌شود.'
            : 'لغو اشتراک ممکن نشد.');
    }
    redirect('/billing.php');
}

$report = usage_status_for($orgId);
$plans = billing_public_plans();
$records = billing_records_for_organization($orgId, 10);
$statusFa = [
    'trialing' => 'دوره‌ی آزمایشی', 'active' => 'فعال', 'past_due' => 'پرداخت معوق',
    'grace' => 'مهلت ارفاقی', 'suspended' => 'معلق', 'cancelled' => 'لغوشده', 'expired' => 'منقضی',
];

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'billing.subscription';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<div class="card">
  <h2>اشتراک فعلی</h2>
  <?php if (!$report['billing_enabled']): ?>
    <p class="hint">سیستم اشتراک روی این نصب فعال نیست — همه‌ی امکانات بدون محدودیت در دسترس است.</p>
  <?php elseif ($report['mode'] === 'grandfathered'): ?>
    <p class="hint">سازمان شما پیش از راه‌اندازی سیستم اشتراک ایجاد شده و بدون محدودیت کار می‌کند.</p>
  <?php else: ?>
    <table>
      <tr><th>پلن</th><td><?= e($report['plan_code'] ?? '—') ?></td></tr>
      <tr><th>وضعیت</th><td>
        <?= e($statusFa[$report['subscription_status']] ?? ($report['subscription_status'] ?? '—')) ?>
        <?= $report['serviceable'] ? '' : ' — سرویس‌های پولی غیرفعال است' ?>
      </td></tr>
      <?php if ($report['current_period_end']): ?>
        <tr><th>پایان دوره</th><td><?= jdate($report['current_period_end']) ?></td></tr>
      <?php endif; ?>
      <?php if ($report['trial_ends_at']): ?>
        <tr><th>پایان دوره‌ی آزمایشی</th><td><?= jdate($report['trial_ends_at']) ?></td></tr>
      <?php endif; ?>
      <?php if ($report['grace_ends_at']): ?>
        <tr><th>پایان مهلت ارفاقی</th><td><?= jdate($report['grace_ends_at']) ?></td></tr>
      <?php endif; ?>
      <?php if ($report['cancel_at_period_end']): ?>
        <tr><th>لغو</th><td>در پایان دوره‌ی جاری لغو می‌شود</td></tr>
      <?php endif; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>مصرف و محدودیت‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>مورد</th><th>مصرف‌شده</th><th>سقف</th><th>باقی‌مانده</th></tr>
    <?php foreach ($report['limits'] as $key => $info): ?>
      <?php if (($info['kind'] ?? '') === 'per_request') continue; ?>
      <tr>
        <td><?= e(Limits::label($key)) ?></td>
        <td class="num"><?= to_persian_digits((string)($info['used'] ?? 0)) ?><?= !empty($info['reserved']) ? ' (+' . to_persian_digits((string)$info['reserved']) . ' رزرو)' : '' ?></td>
        <td class="num"><?= $info['limit'] === null ? 'نامحدود' : to_persian_digits(number_format((int)$info['limit'])) ?></td>
        <td class="num"><?= $info['remaining'] === null ? '—' : to_persian_digits(number_format((int)$info['remaining'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php if ($report['over_limit']): ?>
    <p class="hint" style="color:#c0392b">
      برخی موارد از سقف پلن فعلی بیشتر است. داده‌های موجود شما حذف نمی‌شود؛ فقط تا زمان ارتقای پلن نمی‌توانید مورد جدیدی اضافه کنید.
    </p>
  <?php endif; ?>
</div>

<?php if ($report['billing_enabled'] && membership_has_permission($org, Permissions::BILLING_MANAGE) && $plans): ?>
<div class="card">
  <h2>تغییر پلن</h2>
  <div class="table-wrap">
  <table>
    <tr><th>پلن</th><th>قیمت</th><th>دوره</th><th></th></tr>
    <?php foreach ($plans as $p): ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong><br><span class="hint"><?= e($p['description']) ?></span></td>
        <td class="num"><?= (int)$p['price_amount'] === 0 ? 'رایگان' : to_persian_digits(number_format((int)$p['price_amount'])) . ' ریال' ?></td>
        <td><?= ['none' => '—', 'monthly' => 'ماهانه', 'yearly' => 'سالانه'][$p['billing_period']] ?></td>
        <td>
          <?php if ($p['code'] !== $report['plan_code']): ?>
          <form method="post" onsubmit="return confirm('پلن سازمان به «<?= e($p['name']) ?>» تغییر کند؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="change_plan">
            <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-primary">انتخاب</button>
          </form>
          <?php else: ?><span class="hint">پلن فعلی</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php if ($report['subscription_status'] !== null && !$report['cancel_at_period_end']): ?>
  <form method="post" style="margin-top:14px" onsubmit="return confirm('اشتراک در پایان دوره لغو شود؟ هیچ داده‌ای حذف نمی‌شود.')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="cancel">
    <button class="btn btn-sm btn-danger">لغو اشتراک در پایان دوره</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($records): ?>
<div class="card">
  <h2>سوابق صورتحساب</h2>
  <div class="table-wrap">
  <table>
    <tr><th>تاریخ</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>دوره</th></tr>
    <?php foreach ($records as $r): ?>
      <tr>
        <td><?= jdate($r['created_at'], false) ?></td>
        <td class="ltr"><?= e($r['plan_code']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$r['amount'])) ?> <?= e($r['currency']) ?></td>
        <td><?= ['pending' => 'در انتظار', 'paid' => 'پرداخت‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغوشده'][$r['status']] ?? e($r['status']) ?></td>
        <td><?= $r['period_start'] ? jdate($r['period_start'], false) . ' → ' . jdate($r['period_end'], false) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
