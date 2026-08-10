<?php
/**
 * ELLSMS — start and exit platform-admin support impersonation (docs/admin-impersonation.md).
 *
 * Three surfaces, with deliberately different guards:
 *
 *   GET  ?target=<id>   confirmation page                — require_admin()
 *   POST do=start       begins the support session        — require_admin() + CSRF + rate limit
 *   POST do=exit        returns the operator to the panel — require_login() + CSRF only
 *
 * The exit action MUST NOT be behind require_admin(): while impersonating, the effective user is an
 * ordinary customer, so require_admin() denies — and gating the way OUT behind the privilege the
 * session no longer effectively has would strand the operator inside the customer's panel. It is
 * safe because impersonation_exit() acts solely on validated server-side session state and takes no
 * input at all; there is nothing for a caller to influence.
 *
 * There is no GET route that starts anything (STEP 9): a support session must not be reachable from
 * a link, an <img> tag, or a mistyped URL.
 */
require_once __DIR__ . '/../app/bootstrap.php';

/* ---------------- Exit ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'exit') {
    require_login();
    csrf_check();

    $result = impersonation_exit();
    if (!$result['ok']) {
        // Not impersonating: nothing to undo. Send them somewhere sensible rather than erroring.
        redirect('/index.php');
    }
    flash('success', 'از حالت پشتیبانی خارج شدید.');
    redirect($result['return_to']);
}

/* ---------------- Start ---------------- */
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') !== 'start') {
        redirect('/users.php');
    }

    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $reason       = (string)($_POST['reason'] ?? '');
    $returnTo     = (string)($_POST['return_to'] ?? '/users.php?edit=' . $targetUserId);

    // STEP 35 — an admin cannot walk the user-id space by resubmitting this form. Uses the existing
    // rate-limit infrastructure rather than inventing a second one, keyed on the ACTOR (the thing
    // that must not be able to enumerate) as well as their IP.
    $actorBucket = rate_limit_bucket('impersonate_start', 'user', (string)$me['id']);
    $ipBucket    = rate_limit_bucket('impersonate_start', 'ip', client_ip());
    $max    = rate_limit_config('RATE_LIMIT_IMPERSONATE_MAX', 10);
    $window = rate_limit_config('RATE_LIMIT_IMPERSONATE_WINDOW_SECONDS', 300);
    if (!rate_limit_hit($actorBucket, $max, $window) || !rate_limit_hit($ipBucket, $max, $window)) {
        Logger::warning('impersonation.start_rate_limited', ['impersonator_user_id' => $me['id'], 'target_user_id' => $targetUserId]);
        audit((int)$me['id'], 'impersonation.start_refused', "target=#{$targetUserId} reason=rate_limited");
        flash('error', impersonation_refusal_message('rate_limited'));
        redirect('/users.php');
    }

    // Required in practice, not merely encouraged: an unexplained support session is exactly the
    // kind of access a later review cannot evaluate (STEP 17).
    if (trim($reason) === '') {
        flash('error', 'ثبت دلیل ورود به حالت پشتیبانی الزامی است.');
        redirect('/impersonate.php?target=' . $targetUserId);
    }

    $result = impersonation_start($me, $targetUserId, $reason, $returnTo);
    if (!$result['ok']) {
        flash('error', impersonation_refusal_message((string)$result['reason']));
        redirect('/users.php' . ($targetUserId > 0 ? '?edit=' . $targetUserId : ''));
    }

    flash('info', 'حالت پشتیبانی فعال شد. برخی عملیات حساس در این حالت غیرفعال است.');
    redirect('/index.php');
}

/* ---------------- Confirmation page (GET) ---------------- */
$targetUserId = (int)($_GET['target'] ?? 0);
$target = resolve_ellsms_managed_user($targetUserId);
$refusal = impersonation_target_refusal($target, (int)$me['id']);

$pageTitle = 'ورود به پنل مشتری';
$active = 'users';

// The target's organization, shown so the operator can confirm they are about to enter the right
// customer — the single most likely mistake this page exists to prevent.
$targetOrganization = null;
if ($refusal === null) {
    $memberships = user_organization_memberships((int)$target['id']);
    $targetOrganization = $memberships[0] ?? null;
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ورود به پنل مشتری
    <a class="btn btn-sm btn-ghost" style="float:left" href="/users.php<?= $targetUserId > 0 ? '?edit=' . (int)$targetUserId : '' ?>">← انصراف</a>
  </h2>

  <?php if ($refusal !== null): ?>
    <div class="flash flash-error"><?= e(impersonation_refusal_message($refusal)) ?></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <tr><th>نام کاربری</th><td class="ltr"><?= e((string)$target['username']) ?></td></tr>
      <tr><th>نام</th><td><?= e(trim((string)$target['first_name'] . ' ' . (string)$target['last_name'])) ?></td></tr>
      <tr><th>سازمان</th><td><?= $targetOrganization ? e((string)$targetOrganization['name']) : '<span class="muted">—</span>' ?></td></tr>
      <tr><th>وضعیت حساب</th><td>
        <?php if (!$target['active']): ?>
          <span class="badge badge-off">غیرفعال</span>
        <?php else: ?>
          <span class="badge badge-ok">فعال</span>
        <?php endif; ?>
      </td></tr>
    </table>
    </div>

    <p class="hint" style="margin-top:12px">
      با ورود به حالت پشتیبانی، پنل این مشتری را با سطح دسترسی خودِ کاربر مشاهده می‌کنید.
      این نشست ثبت و ممیزی می‌شود و برخی عملیات حساس غیرفعال خواهند بود.
    </p>
    <p class="hint">
      در این حالت ارسال واقعی پیامک، تغییر رمز عبور و ورود دومرحله‌ای، ساخت/چرخش کلید API و وب‌هوک،
      تغییر اشتراک و اعتبار، و حذف داده‌ها امکان‌پذیر نیست. دسترسی به بخش «مدیریت» نیز تا خروج از این حالت بسته است.
      حداکثر مدت این نشست <?= to_persian_digits((string)(IMPERSONATION_MAX_SECONDS / 60)) ?> دقیقه است.
    </p>

    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="start">
      <input type="hidden" name="target_user_id" value="<?= (int)$target['id'] ?>">
      <input type="hidden" name="return_to" value="/users.php?edit=<?= (int)$target['id'] ?>">
      <label>دلیل ورود (الزامی)
        <input type="text" name="reason" required maxlength="<?= IMPERSONATION_REASON_MAX_LENGTH ?>"
               placeholder="مثلاً: بررسی تیکت #۱۲۳۴ — گزارش مشتری درباره‌ی ارسال ناموفق">
      </label>
      <button class="btn btn-primary">ورود به حالت پشتیبانی</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
