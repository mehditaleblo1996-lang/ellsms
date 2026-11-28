<?php
/*
 * The top of a user's page on /users.php?edit=… (docs/user-admin-header.md):
 *   1. the profile card — who the account is, its status chips, the key facts at a glance and the
 *      quick actions (enter the customer's panel, change credit, set a password);
 *   2. «تنظیمات و دسترسی‌ها» — every per-user admin switch as one row with a toggle that saves on
 *      change (each still a plain POST form, so it works without JavaScript via its «ثبت» button).
 *
 * Expects from public/users.php: $me, $editUser, $editUserId, $editCustomer, $editOrganization,
 * $editOrgProfile, $editSendNumbers, $editLegacyOriginator, $editSentCount, $editLastLogin.
 */

$uhId = (int)$editUser['id'];
$uhName = trim($editUser['first_name'] . ' ' . $editUser['last_name']);
$uhInitial = mb_strtoupper(mb_substr($uhName !== '' ? $uhName : (string)$editUser['username'], 0, 1));
$uhIsSelf = $uhId === (int)$me['id'];
$uhInactive = !$editUser['active'] || $editUser['deleted'];
$uhSuspended = !empty($editCustomer['suspended']);
$uhExpires = $editCustomer['expires_on'] ?? null;
$uhExpired = $uhExpires !== null && $uhExpires < date('Y-m-d');
$uhAccountType = $editOrgProfile ? (PROFILE_ACCOUNT_TYPES[$editOrgProfile['account_type'] ?? 'individual'] ?? null) : null;
$uhCustomerLabel = customer_type_label($editCustomer);

// The sender line(s) the account actually sends from (same rule as the send path).
if ($editUser['is_admin']) {
    $uhLine = 'همه‌ی خطوط';
    $uhLineLtr = false;
} elseif ($editSendNumbers) {
    $uhLine = (string)$editSendNumbers[0]['number'] . (count($editSendNumbers) > 1 ? ' +' . (count($editSendNumbers) - 1) : '');
    $uhLineLtr = true;
} else {
    $uhLine = '';
    $uhLineLtr = false;
}

$uhUsername = static fn(?int $id): string => $id === null ? '' : (backend_usernames_by_ids([$id])[$id] ?? '#' . $id);
$uhSince = static function (?string $at, ?int $by) use ($uhUsername): string {
    if ($at === null) return '';
    return 'از ' . jdate_from_utc($at) . ($by !== null ? ' — ' . e($uhUsername($by)) : '');
};

/*
 * One row of «تنظیمات و دسترسی‌ها». $hidden are the form's own fields besides csrf/id/back;
 * $name is the checkbox's field name. $confirm (optional) is asked before saving.
 */
$uhSwitch = static function (array $row) use ($uhId): void { ?>
  <li class="uh-setting<?= $row['on'] ? ' is-on' : '' ?><?= !empty($row['accent']) ? ' uh-setting-' . $row['accent'] : '' ?>">
    <span class="uh-setting-icon" aria-hidden="true"><?= panel_icon($row['icon']) ?></span>
    <div class="uh-setting-text">
      <div class="uh-setting-title"><?= e($row['title']) ?>
        <span class="uh-state"><?= $row['on'] ? e($row['on_label'] ?? 'فعال') : e($row['off_label'] ?? 'غیرفعال') ?></span>
      </div>
      <p class="uh-setting-desc"><?= $row['desc'] ?></p>
      <?php if (!empty($row['meta'])): ?><p class="uh-setting-meta"><?= $row['meta'] ?></p><?php endif; ?>
    </div>
    <?php if (!empty($row['locked'])): ?>
      <span class="uh-locked" data-tip="<?= e($row['locked']) ?>"><?= panel_icon('lock') ?><span class="sr-only"><?= e($row['locked']) ?></span></span>
    <?php else: ?>
    <form method="post" class="uh-switch-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="<?= e($row['do']) ?>">
      <input type="hidden" name="id" value="<?= $uhId ?>">
      <input type="hidden" name="back" value="1">
      <?php foreach ($row['hidden'] ?? [] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
      <label class="uh-switch">
        <input type="checkbox" role="switch" name="<?= e($row['name']) ?>" value="1"<?= $row['on'] ? ' checked' : '' ?>
               data-autosubmit<?= !empty($row['confirm']) ? ' data-confirm="' . e($row['confirm']) . '"' : '' ?>>
        <span class="uh-switch-track" aria-hidden="true"></span>
        <span class="sr-only"><?= e($row['title']) ?></span>
      </label>
      <button class="btn btn-sm uh-switch-submit">ثبت</button>
    </form>
    <?php endif; ?>
  </li>
<?php };
?>
<section class="card uh" aria-labelledby="uh-title">
  <div class="uh-top">
    <div class="uh-ident">
      <div class="uh-avatar<?= vip_is($uhId) ? ' is-vip' : '' ?>" aria-hidden="true"><?= e($uhInitial) ?></div>
      <div class="uh-ident-text">
        <h2 id="uh-title"><span class="ltr"><?= e($editUser['username']) ?></span><?= vip_is($uhId) ? ' ' . vip_badge() : '' ?></h2>
        <p class="uh-subtitle">
          <?= $uhName !== '' ? e($uhName) : '<span class="muted">بدون نام</span>' ?>
          <span class="uh-dot" aria-hidden="true">·</span>
          <span>شناسه <span class="num"><?= to_persian_digits((string)$uhId) ?></span></span>
          <?php if ($editOrganization && !empty($editOrganization['name'])): ?>
            <span class="uh-dot" aria-hidden="true">·</span><span><?= e((string)$editOrganization['name']) ?></span>
          <?php endif; ?>
        </p>
        <ul class="uh-chips" aria-label="وضعیت حساب">
          <li><span class="badge badge-<?= $uhInactive ? 'off' : 'ok' ?>"><?= $editUser['deleted'] ? 'حذف‌شده' : ($editUser['active'] ? 'فعال' : 'غیرفعال') ?></span></li>
          <?php if ($uhSuspended): ?><li><span class="badge badge-off">تعلیق‌شده</span></li><?php endif; ?>
          <?php if ($uhExpired): ?><li><span class="badge badge-off">منقضی‌شده</span></li><?php endif; ?>
          <li><span class="badge badge-<?= $editUser['is_admin'] ? 'admin' : 'user' ?>"><?= $editUser['is_admin'] ? 'مدیر' : 'کاربر' ?></span></li>
          <li><span class="badge badge-unknown"><?= e($uhCustomerLabel) ?></span></li>
          <?php if ($uhAccountType): ?><li><span class="badge badge-unknown"><?= e($uhAccountType) ?></span></li><?php endif; ?>
          <li><span class="badge badge-<?= $editUser['twofa_enabled'] ? 'ok' : 'pending' ?>"><?= panel_icon('shield') ?> ۲مرحله‌ای <?= $editUser['twofa_enabled'] ? 'فعال' : 'غیرفعال' ?></span></li>
        </ul>
      </div>
    </div>
    <div class="uh-actions">
      <?php if (impersonation_target_refusal($editUser, (int)$me['id']) === null): ?>
        <a class="btn btn-primary btn-sm" href="/impersonate.php?target=<?= $uhId ?>"><?= panel_icon('login') ?> ورود به پنل مشتری</a>
      <?php endif; ?>
      <a class="btn btn-sm" href="#account" data-jump="uh-credit"><?= panel_icon('wallet') ?> تغییر اعتبار</a>
      <a class="btn btn-sm" href="#account" data-jump="uh-password"><?= panel_icon('key') ?> رمز عبور</a>
      <a class="btn btn-sm btn-ghost" href="/users.php">← بازگشت به فهرست</a>
    </div>
  </div>

  <?php if ($uhInactive): ?>
    <div class="flash flash-error">این حساب غیرفعال یا حذف‌شده است — تا زمانی که در سامانه‌ی مرکزی اصلاح نشود، امکان ورود ندارد.</div>
  <?php elseif ($uhSuspended): ?>
    <div class="flash flash-error">این حساب تعلیق شده است و تا رفع تعلیق (زبانه‌ی «نمایندگی و دسترسی منو») نمی‌تواند پیامک ارسال کند.</div>
  <?php endif; ?>

  <dl class="uh-facts">
    <div class="uh-fact uh-fact-credit">
      <dt><?= panel_icon('wallet') ?> اعتبار</dt>
      <dd><span class="num"><?= to_persian_digits(number_format((float)$editUser['credit'])) ?></span> <small>ریال</small></dd>
    </div>
    <div class="uh-fact">
      <dt><?= panel_icon('send') ?> پیامک ارسال‌شده</dt>
      <dd><span class="num"><?= to_persian_digits(number_format($editSentCount)) ?></span></dd>
    </div>
    <div class="uh-fact">
      <dt><?= panel_icon('phone') ?> موبایل</dt>
      <dd class="uh-copyable">
        <?php if ((string)$editUser['mobile'] !== ''): ?>
          <span class="ltr msisdn"><?= e((string)$editUser['mobile']) ?></span>
          <button type="button" class="uh-copy" data-copy="<?= e((string)$editUser['mobile']) ?>" aria-label="کپی موبایل" data-tip="کپی"><?= panel_icon('copy') ?></button>
        <?php else: ?><span class="muted">ثبت نشده</span><?php endif; ?>
      </dd>
    </div>
    <div class="uh-fact">
      <dt><?= panel_icon('message') ?> ایمیل</dt>
      <dd class="uh-copyable">
        <?php if ((string)($editUser['email'] ?? '') !== ''): ?>
          <span class="ltr uh-ellipsis" title="<?= e((string)$editUser['email']) ?>"><?= e((string)$editUser['email']) ?></span>
          <button type="button" class="uh-copy" data-copy="<?= e((string)$editUser['email']) ?>" aria-label="کپی ایمیل" data-tip="کپی"><?= panel_icon('copy') ?></button>
        <?php else: ?><span class="muted">ثبت نشده</span><?php endif; ?>
      </dd>
    </div>
    <div class="uh-fact">
      <dt><?= panel_icon('link') ?> خط ارسال</dt>
      <dd>
        <?php if ($uhLine !== ''): ?>
          <span class="<?= $uhLineLtr ? 'ltr msisdn' : '' ?>"><?= e($uhLine) ?></span>
        <?php else: ?>
          <a class="uh-warn-link" href="/numbers.php">خطی تخصیص نیافته</a>
        <?php endif; ?>
      </dd>
    </div>
    <div class="uh-fact">
      <dt><?= panel_icon('clock') ?> آخرین ورود</dt>
      <dd>
        <?php if ($editLastLogin): ?>
          <span><?= jdate_from_utc($editLastLogin['created_at']) ?></span>
          <?php if ((string)$editLastLogin['ip'] !== ''): ?><small class="ltr uh-ip"><?= e((string)$editLastLogin['ip']) ?></small><?php endif; ?>
        <?php else: ?><span class="muted">ثبت نشده</span><?php endif; ?>
      </dd>
    </div>
    <?php if ($uhExpires !== null): ?>
    <div class="uh-fact<?= $uhExpired ? ' is-bad' : '' ?>">
      <dt><?= panel_icon('calendar') ?> اعتبار حساب تا</dt>
      <dd><?= jdate($uhExpires, false) ?></dd>
    </div>
    <?php endif; ?>
  </dl>
</section>

<section class="card uh-settings" aria-labelledby="uh-settings-title">
  <div class="card-head">
    <div>
      <h2 id="uh-settings-title">تنظیمات و دسترسی‌ها</h2>
      <p class="hint">هر کلید با تغییر، بلافاصله ذخیره می‌شود. این تنظیمات فقط در دست مدیر است و کاربر آن‌ها را نمی‌بیند.</p>
    </div>
  </div>
  <ul class="uh-settings-list">
    <?php
    $uhSwitch([
        'icon' => 'user', 'title' => 'نقش مدیر', 'do' => 'toggle_admin', 'name' => 'on', 'hidden' => ['set' => 1],
        'on' => (bool)$editUser['is_admin'], 'on_label' => 'مدیر', 'off_label' => 'کاربر عادی',
        'desc' => 'مدیر به همه‌ی بخش‌های مدیریتی، همه‌ی کاربران و همه‌ی خطوط دسترسی دارد.',
        'confirm' => $editUser['is_admin'] ? 'نقش مدیر از این حساب گرفته شود؟' : 'این حساب «مدیر» شود؟ مدیر به همه‌ی بخش‌های مدیریتی دسترسی دارد.',
        'locked' => $uhIsSelf ? 'نقش مدیر حساب خودتان را نمی‌توانید تغییر دهید.' : null,
        'accent' => 'admin',
    ]);
    $uhSwitch([
        'icon' => 'shield', 'title' => 'ورود دومرحله‌ای', 'do' => 'toggle_2fa', 'name' => 'on', 'hidden' => ['set' => 1],
        'on' => (bool)$editUser['twofa_enabled'],
        'desc' => 'هنگام ورود، علاوه بر رمز عبور، کد یکبارمصرف پیامکی (یا برنامه‌ی احراز هویت) خواسته می‌شود.',
    ]);
    if (vip_available()) {
        $editVip = vip_details($uhId);
        $uhSwitch([
            'icon' => 'star', 'title' => 'مشتری ویژه', 'do' => 'vip_save', 'name' => 'is_vip',
            'on' => $editVip !== null, 'on_label' => 'ویژه', 'off_label' => 'عادی', 'accent' => 'vip',
            'desc' => 'در فهرست کاربران و گزارش‌ها با نشان «★ ویژه» مشخص و قابل فیلتر می‌شود.',
            'meta' => $editVip ? $uhSince($editVip['marked_at'], $editVip['marked_by']) : '',
        ]);
    }
    if (p2p_auto_send_available()) {
        $editAuto = p2p_auto_send_details($uhId);
        $uhSwitch([
            'icon' => 'upload', 'title' => 'ارسال خودکار نظیر به نظیر', 'do' => 'p2p_auto_send_save', 'name' => 'p2p_auto_send',
            'on' => $editAuto !== null, 'on_label' => 'بدون تأیید', 'off_label' => 'با تأیید',
            'desc' => 'فایل‌های حجیم نظیر به نظیر پس از بارگذاری، بدون «ادامه و تأیید ارسال» شروع به ارسال می‌کنند.',
            'meta' => $editAuto ? $uhSince($editAuto['set_at'], $editAuto['set_by']) : '',
        ]);
    }
    if (optout_suffix_user_column_available()) {
        $uhSwitch([
            'icon' => 'ban', 'title' => 'افزودن «لغو۱۱» به انتهای پیام', 'do' => 'optout_suffix_save', 'name' => 'optout_suffix',
            'on' => optout_suffix_for_user($uhId),
            'desc' => '«لغو۱۱» در یک خط جدید به انتهای پیام‌هایی که این کاربر از پنل می‌فرستد اضافه می‌شود (اگر از قبل در متن نباشد). کاربر نمی‌تواند آن را تغییر دهد؛ روی API اثری ندارد.',
        ]);
    }
    ?>
  </ul>
</section>
