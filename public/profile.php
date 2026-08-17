<?php
/**
 * ELLSMS — the user's own account page: password, personal profile, and (for members of an
 * organization) the company profile, address, low-credit alerts, documents, KYC status/submission
 * and allowed-IP management (docs/customer-profile.md, docs/profile-kyc.md).
 *
 * EDIT POLICY, enforced server-side on every branch below:
 *   - personal profile and personal documents: always the user's own, no permission needed;
 *   - company profile / address / alerts / organization documents / account type / allowed IPs / KYC
 *     submission: `settings.manage` only, i.e. owner and admin — an ordinary member READS them and
 *     cannot change the company's legal record or trigger a KYC submission on the organization's behalf.
 *
 * The organization shown is the ACTIVE one (current_organization()). A user who belongs to two
 * organizations sees the company profile of whichever is active and never a merge of the two.
 *
 * LAYOUT (docs/profile-kyc.md §UI): account-type switcher -> read-only account summary -> KYC status
 * -> individual OR legal profile section -> address -> documents (tile grid) -> security/alerts/
 * allowed IPs, each its own card. Section B ("اطلاعات حساب") is DELIBERATELY read-only summary, never
 * a second edit form for fields already editable further down the page — the previous layout's
 * "confusing overlap between personal profile and KYC information" was exactly two forms able to
 * write the same field; this page now has exactly one write path per field, and the summary only
 * ever reads it back.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'حساب کاربری';
$active = '';

$organization = current_organization();
$organizationId = $organization ? (int)$organization['organization_id'] : 0;
$canManageOrganization = profile_can_manage_organization($organization);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? 'password';

    // Support impersonation is read-only for anything that changes a customer's identity, legal
    // record or documents (docs/admin-impersonation.md). Credentials were already off-limits.
    $impersonationAction = [
        'password'              => 'account.password',
        'profile_personal'      => 'profile.personal',
        'account_type'          => 'profile.organization',
        'profile_organization'  => 'profile.organization',
        'profile_address'       => 'profile.organization',
        'profile_notifications' => 'profile.organization',
        'document_upload'       => 'profile.documents',
        'document_archive'      => 'profile.documents',
        'kyc_submit'            => 'profile.organization',
        'allowed_ip_create'     => 'profile.organization',
        'allowed_ip_delete'     => 'profile.organization',
        'allowed_ip_toggle'     => 'profile.organization',
    ][$do] ?? null;
    if ($impersonationAction !== null && impersonation_guard_post($impersonationAction)) {
        redirect('/profile.php');
    }

    // Every organization-scoped branch needs the same two things: an active organization and
    // settings.manage. Checked once, here, rather than repeated (and eventually forgotten) below.
    $organizationScoped = in_array($do, [
        'account_type', 'profile_organization', 'profile_address', 'profile_notifications',
        'kyc_submit', 'allowed_ip_create', 'allowed_ip_delete', 'allowed_ip_toggle',
    ], true)
        || ($do === 'document_upload' && ($_POST['owner'] ?? '') === 'organization')
        || ($do === 'document_archive' && ($_POST['owner'] ?? '') === 'organization');
    if ($organizationScoped && !($organizationId > 0 && $canManageOrganization)) {
        flash('error', 'شما اجازه‌ی تغییر اطلاعات سازمان را ندارید.');
        redirect('/profile.php');
    }

    if ($do === 'password') {
        $cur = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $rep = $_POST['repeat'] ?? '';

        // Phase 8 (Invariant B): identity provider, not a direct user_ query.
        $hash = backend_user_password_hash((int)$me['id']);

        if (!backend_verify_password($cur, (string)$hash)) {
            flash('error', 'رمز عبور فعلی درست نیست.');
        } elseif (strlen($new) < 6) {
            flash('error', 'رمز عبور جدید باید حداقل ۶ نویسه باشد.');
        } elseif ($new !== $rep) {
            flash('error', 'دو رمز عبور جدید یکسان نیستند.');
        } else {
            backend_update_user_password((int)$me['id'], backend_hash_password($new));
            audit((int)$me['id'], 'password.change');
            flash('success', 'رمز عبور تغییر کرد — این تغییر همه‌جا برای این حساب اعمال می‌شود.');
        }
    } elseif ($do === 'profile_personal') {
        $result = profile_user_save((int)$me['id'], [
            'father_name'           => $_POST['father_name'] ?? '',
            'national_code'         => $_POST['national_code'] ?? '',
            'birth_certificate_no'  => $_POST['birth_certificate_no'] ?? '',
            'birth_date'            => profile_date_from_request('birth_date'),
            'national_id_expiry_at' => profile_date_from_request('national_id_expiry_at'),
            'gender'                => $_POST['gender'] ?? 'unspecified',
            'personal_address'      => $_POST['personal_address'] ?? '',
        ], (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'اطلاعات فردی ذخیره شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'account_type') {
        // A STANDALONE action, deliberately: switching حقیقی/حقوقی is a metadata decision the owner/
        // admin should be able to make without re-submitting the entire company form, and it must
        // NEVER blank out the other fields already on file (§13 — no silent data loss; the dormant
        // side's data and documents simply stay stored and unused until switched back).
        $current = profile_organization_get($organizationId);
        $current['account_type'] = $_POST['account_type'] ?? $current['account_type'];
        $result = profile_organization_save($organizationId, $current, (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'نوع حساب به‌روزرسانی شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'profile_organization') {
        $result = profile_organization_save($organizationId, [
            'account_type'                 => $_POST['account_type'] ?? 'individual',
            'legal_name'                   => $_POST['legal_name'] ?? '',
            'company_type'                 => $_POST['company_type'] ?? 'unspecified',
            'registration_number'          => $_POST['registration_number'] ?? '',
            'national_id'                  => $_POST['national_id'] ?? '',
            'economic_code'                => $_POST['economic_code'] ?? '',
            'ceo_name'                     => $_POST['ceo_name'] ?? '',
            'ceo_last_name'                => $_POST['ceo_last_name'] ?? '',
            'ceo_father_name'              => $_POST['ceo_father_name'] ?? '',
            'ceo_national_code'            => $_POST['ceo_national_code'] ?? '',
            'ceo_birth_certificate_no'     => $_POST['ceo_birth_certificate_no'] ?? '',
            'ceo_birth_date'               => profile_date_from_request('ceo_birth_date'),
            'ceo_birth_city'               => $_POST['ceo_birth_city'] ?? '',
            'ceo_mobile'                   => $_POST['ceo_mobile'] ?? '',
            'ceo_email'                    => $_POST['ceo_email'] ?? '',
            'landline_phone'               => $_POST['landline_phone'] ?? '',
            'fax_number'                   => $_POST['fax_number'] ?? '',
            'customer_code'                => $_POST['customer_code'] ?? '',
            'company_start_date'           => profile_date_from_request('company_start_date'),
            'company_expiry_date'          => profile_date_from_request('company_expiry_date'),
            'legal_representative_user_id' => $_POST['legal_representative_user_id'] ?? 0,
        ], (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'اطلاعات ذخیره شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'profile_address') {
        $result = profile_address_save($organizationId, $_POST, (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'آدرس ذخیره شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'profile_notifications') {
        $result = profile_notifications_save($organizationId, $_POST, (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'تنظیمات اعلان ذخیره شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'document_upload') {
        $owner = ($_POST['owner'] ?? 'user') === 'organization'
            ? ['organization' => $organizationId]
            : ['user' => (int)$me['id']];
        try {
            profile_document_store($owner, (string)($_POST['document_type'] ?? ''), 'document', (int)$me['id']);
            flash('success', 'مدرک بارگذاری شد.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    } elseif ($do === 'document_archive') {
        $owner = ($_POST['owner'] ?? 'user') === 'organization'
            ? ['organization' => $organizationId]
            : ['user' => (int)$me['id']];
        $result = profile_document_archive($owner, (int)($_POST['document_id'] ?? 0), (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'مدرک بایگانی شد.' : 'مدرک یافت نشد.');
    } elseif ($do === 'kyc_submit') {
        $accountType = profile_organization_get($organizationId)['account_type'] ?? 'individual';
        $result = kyc_submit(
            $organizationId, (int)$me['id'], $accountType,
            profile_user_get((int)$me['id']), profile_organization_get($organizationId), profile_address_get($organizationId)
        );
        if ($result['ok']) {
            flash('success', 'درخواست احراز هویت ارسال شد.');
        } elseif (($result['reason'] ?? '') === 'incomplete') {
            flash('error', 'برای ارسال، ابتدا موارد زیر را تکمیل کنید: ' . implode('، ', $result['missing']));
        } else {
            flash('error', 'در وضعیت فعلی امکان ارسال درخواست وجود ندارد.');
        }
    } elseif ($do === 'allowed_ip_create') {
        $result = allowed_ip_create($organizationId, (string)($_POST['ip_or_cidr'] ?? ''), (string)($_POST['label'] ?? ''), (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'آدرس اضافه شد.' : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'allowed_ip_delete') {
        $result = allowed_ip_delete($organizationId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'آدرس حذف شد.' : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'allowed_ip_toggle') {
        $result = allowed_ip_toggle($organizationId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'وضعیت به‌روزرسانی شد.' : allowed_ip_error_message((string)$result['reason']));
    }

    redirect('/profile.php');
}

$userProfile   = profile_user_get((int)$me['id']);
$userDocuments = profile_documents_list(['user' => (int)$me['id']]);

$organizationProfile = $organizationId > 0 ? profile_organization_get($organizationId) : null;
$address             = $organizationId > 0 ? profile_address_get($organizationId) : null;
$notifications       = $organizationId > 0 ? profile_notifications_get($organizationId) : null;
$organizationDocuments = $organizationId > 0 ? profile_documents_list(['organization' => $organizationId]) : [];
$allowedIps          = $organizationId > 0 ? allowed_ip_list($organizationId) : [];
$accountType         = $organizationProfile['account_type'] ?? 'individual';
// §13 — "no silent data loss": an organization that already has company/representative data on file
// (e.g. from before an account_type switch, or set directly by an admin) keeps that section visible
// even while account_type reads 'individual' — hiding a section never hides the DATA behind it, only
// the default layout for an organization that has never touched it.
$hasLegalData        = $organizationId > 0 && (
    (string)($organizationProfile['legal_name'] ?? '') !== ''
    || (string)($organizationProfile['national_id'] ?? '') !== ''
    || (string)($organizationProfile['ceo_name'] ?? '') !== ''
);
$score               = $organizationId > 0
    ? profile_account_completeness($accountType, $userProfile, $organizationProfile, $address)
    : profile_user_completeness($userProfile);
$kycRequest          = $organizationId > 0 ? kyc_request_get($organizationId) : null;
$kycEligibility      = $organizationId > 0
    ? kyc_can_submit($organizationId, (int)$me['id'], $accountType, $userProfile, $organizationProfile, $address)
    : ['ok' => false, 'missing' => []];
$kycBadgeClass = [
    'draft' => 'badge-off', 'rejected' => 'badge-off',
    'submitted' => 'badge-pending', 'under_review' => 'badge-pending', 'needs_correction' => 'badge-pending',
    'approved' => 'badge-ok',
][$kycRequest['status'] ?? 'draft'] ?? 'badge-off';
$reviewBadgeClass = ['pending' => 'badge-pending', 'approved' => 'badge-ok', 'rejected' => 'badge-off'];

// Active-document lookup by type, for the document tile grid below — only the CURRENTLY active
// version of each type is tiled (a document's full replace history stays reachable through
// public/kyc-review.php for an admin; this self-service view only needs "what do I have right now").
$activeUserDocsByType = [];
foreach ($userDocuments as $d) {
    if ($d['status'] === 'active') {
        $activeUserDocsByType[$d['document_type']] = $d;
    }
}
$activeOrgDocsByType = [];
foreach ($organizationDocuments as $d) {
    if ($d['status'] === 'active') {
        $activeOrgDocsByType[$d['document_type']] = $d;
    }
}

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'profile.personal';
require __DIR__ . '/../app/views/impersonation_notice.php';
$profileReadOnly = is_impersonating();
?>

<?php if ($organizationId > 0): ?>
<div class="card">
  <h2>نوع حساب</h2>
  <p class="hint">با انتخاب نوع حساب، بخش‌های متناسب با آن (اطلاعات فردی یا اطلاعات شرکت/نماینده) نمایش داده می‌شود. تغییر نوع حساب، اطلاعات یا مدارک بخش دیگر را حذف نمی‌کند.</p>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="account_type">
    <div class="segmented" role="radiogroup" aria-label="نوع حساب">
      <?php foreach (PROFILE_ACCOUNT_TYPES as $value => $label): ?>
        <input type="radio" id="account_type_<?= e($value) ?>" name="account_type" value="<?= e($value) ?>"
               <?= $accountType === $value ? ' checked' : '' ?> <?= $canManageOrganization ? '' : 'disabled' ?>
               onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit();">
        <label for="account_type_<?= e($value) ?>"><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <noscript><button class="btn btn-sm" style="margin-inline-start:10px">اعمال</button></noscript>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>اطلاعات حساب</h2>
  <p class="hint">این کارت یک نمای خلاصه و فقط‌خواندنی است؛ ویرایش هر مقدار از همان بخش اختصاصی آن در ادامه‌ی صفحه انجام می‌شود.</p>
  <div class="summary-grid" style="margin-top:14px">
    <div class="summary-item">
      <div class="summary-label">نام کاربری <span class="field-source">سامانه مرکزی</span></div>
      <div class="summary-value ltr"><?= e((string)$me['username']) ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">نام <span class="field-source">سامانه مرکزی</span></div>
      <div class="summary-value"><?= e((string)$me['first_name']) ?: '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">نام خانوادگی <span class="field-source">سامانه مرکزی</span></div>
      <div class="summary-value"><?= e((string)$me['last_name']) ?: '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">کد ملی</div>
      <div class="summary-value ltr"><?= e((string)$userProfile['national_code']) ?: '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">تاریخ انقضا (کارت ملی)</div>
      <div class="summary-value"><?= $userProfile['national_id_expiry_at'] ? e(jdate((string)$userProfile['national_id_expiry_at'])) : '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">تلفن همراه <span class="field-source">سامانه مرکزی</span></div>
      <div class="summary-value ltr"><?= e((string)$me['mobile']) ?: '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">ایمیل <span class="field-source">سامانه مرکزی</span></div>
      <div class="summary-value ltr"><?= e((string)$me['email']) ?: '—' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">جنسیت</div>
      <div class="summary-value"><?= e(PROFILE_GENDERS[$userProfile['gender'] ?? 'unspecified'] ?? '—') ?></div>
    </div>
    <?php if ($organizationId > 0): ?>
    <div class="summary-item">
      <div class="summary-label">IP فعال/مجاز</div>
      <div class="summary-value"><?= to_persian_digits((string)count(array_filter($allowedIps, fn($ip) => $ip['status'] === 'active'))) ?> از <?= to_persian_digits((string)count($allowedIps)) ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">حداقل اعتبار برای هشدار اتمام اعتبار</div>
      <div class="summary-value ltr"><?= $notifications['low_credit_alert_enabled'] ? to_persian_digits(number_format((int)$notifications['low_credit_threshold'])) : 'غیرفعال' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-label">تکمیل پروفایل</div>
      <div class="summary-value"><?= to_persian_digits((string)$score['percent']) ?>٪</div>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($organizationId > 0 && $score['missing']): ?>
    <p class="hint" style="margin-top:12px">تکمیل‌نشده: <?= e(implode('، ', $score['missing'])) ?></p>
  <?php endif; ?>
</div>

<?php if ($organizationId > 0): ?>
<div class="card">
  <h2>وضعیت احراز هویت (KYC)
    <span class="badge <?= e($kycBadgeClass) ?>" style="margin-inline-start:8px"><?= e(kyc_status_label((string)$kycRequest['status'])) ?></span>
  </h2>
  <div class="table-wrap">
  <table>
    <tr><th>تاریخ ارسال</th><td><?= $kycRequest['submitted_at'] ? e(jdate((string)$kycRequest['submitted_at'])) : '—' ?></td></tr>
    <tr><th>تاریخ آخرین بررسی</th><td><?= $kycRequest['reviewed_at'] ? e(jdate((string)$kycRequest['reviewed_at'])) : '—' ?></td></tr>
  </table>
  </div>
  <?php if (in_array($kycRequest['status'], ['needs_correction', 'rejected'], true) && (string)$kycRequest['review_note'] !== ''): ?>
    <div class="flash flash-error" style="margin-top:14px">یادداشت بازبین: <?= e((string)$kycRequest['review_note']) ?></div>
  <?php endif; ?>
  <?php if ($canManageOrganization && in_array($kycRequest['status'], ['draft', 'needs_correction', 'rejected'], true)): ?>
    <?php if (!$kycEligibility['ok']): ?>
      <p class="hint" style="margin-top:10px">پیش از ارسال باید تکمیل شود: <?= e(implode('، ', $kycEligibility['missing'])) ?></p>
    <?php endif; ?>
    <form method="post" style="margin:12px 0 0">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="kyc_submit">
      <button class="btn btn-primary btn-sm" <?= ($kycEligibility['ok'] && !$profileReadOnly) ? '' : 'disabled' ?>>ارسال درخواست احراز هویت</button>
    </form>
  <?php elseif (in_array($kycRequest['status'], ['submitted', 'under_review'], true)): ?>
    <p class="hint" style="margin-top:10px">درخواست شما ثبت شده و در انتظار بررسی است.</p>
  <?php elseif ($kycRequest['status'] === 'approved'): ?>
    <p class="hint" style="margin-top:10px">احراز هویت این سازمان تأیید شده است.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($accountType !== 'legal' || $organizationId === 0): ?>
<div class="card">
  <h2>اطلاعات فردی</h2>
  <?php if (!empty($userProfile['from_legacy_kyc'])): ?>
    <div class="flash flash-info">بخشی از این اطلاعات از بخش «اطلاعات هویتی» قبلی خوانده شده است و با نخستین ذخیره‌سازی به‌روزرسانی می‌شود.</div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_personal">
    <div class="grid grid-2">
      <label>نام <span class="field-source">سامانه مرکزی</span> <input type="text" value="<?= e((string)$me['first_name']) ?>" disabled></label>
      <label>نام خانوادگی <span class="field-source">سامانه مرکزی</span> <input type="text" value="<?= e((string)$me['last_name']) ?>" disabled></label>
      <label>نام پدر <input type="text" name="father_name" value="<?= e((string)$userProfile['father_name']) ?>" maxlength="120"></label>
      <label>کد ملی <input type="text" name="national_code" class="ltr" value="<?= e((string)$userProfile['national_code']) ?>" maxlength="20" placeholder="۱۰ رقم"></label>
      <label>شماره شناسنامه <input type="text" name="birth_certificate_no" class="ltr" value="<?= e((string)$userProfile['birth_certificate_no']) ?>" maxlength="30"></label>
      <label>تاریخ تولد <?= jalali_date_select('birth_date', $userProfile['birth_date'] ?? null) ?></label>
      <label>جنسیت
        <select name="gender">
          <?php foreach (PROFILE_GENDERS as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($userProfile['gender'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>تاریخ انقضای کارت ملی <?= jalali_date_select('national_id_expiry_at', $userProfile['national_id_expiry_at'] ?? null, 15) ?></label>
      <label>موبایل <span class="field-source">سامانه مرکزی</span> <input type="text" class="ltr" value="<?= e((string)$me['mobile']) ?>" disabled></label>
      <label>ایمیل <span class="field-source">سامانه مرکزی</span> <input type="text" class="ltr" value="<?= e((string)$me['email']) ?>" disabled></label>
      <label style="grid-column:1/-1">آدرس شخصی <input type="text" name="personal_address" value="<?= e((string)($userProfile['personal_address'] ?? '')) ?>" maxlength="500"></label>
    </div>
    <?php if (!$profileReadOnly): ?>
      <button class="btn btn-primary" style="margin-top:12px">ذخیره‌ی اطلاعات فردی</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if ($organizationId > 0 && ($accountType === 'legal' || $hasLegalData)): ?>
<div class="card">
  <h2>اطلاعات شرکت و نماینده</h2>
  <?php if (!$canManageOrganization): ?>
    <p class="hint">شما به این اطلاعات دسترسی مشاهده دارید؛ ویرایش آن‌ها نیازمند دسترسی مدیریت تنظیمات سازمان است.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_organization">
    <input type="hidden" name="account_type" value="legal">

    <div class="subsection">
      <h3 class="subsection-title">اطلاعات شرکت</h3>
      <div class="grid grid-2">
        <label>نام شرکت <input type="text" name="legal_name" value="<?= e((string)$organizationProfile['legal_name']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>شناسه ملی شرکت <input type="text" name="national_id" class="ltr" value="<?= e((string)$organizationProfile['national_id']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>شماره ثبت <input type="text" name="registration_number" class="ltr" value="<?= e((string)$organizationProfile['registration_number']) ?>" maxlength="40" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>کد اقتصادی <input type="text" name="economic_code" class="ltr" value="<?= e((string)$organizationProfile['economic_code']) ?>" maxlength="30" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>نوع شرکت
          <select name="company_type" <?= $canManageOrganization ? '' : 'disabled' ?>>
            <?php foreach (PROFILE_COMPANY_TYPES as $value => $label): ?>
              <?php if ($value === 'unspecified') continue; ?>
              <option value="<?= e($value) ?>"<?= ($organizationProfile['company_type'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>کد مشتری <input type="text" name="customer_code" class="ltr" value="<?= e((string)$organizationProfile['customer_code']) ?>" maxlength="40" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>تاریخ شروع شرکت <?= jalali_date_select('company_start_date', $organizationProfile['company_start_date'] ?? null) ?></label>
        <label>تاریخ انقضای شرکت <?= jalali_date_select('company_expiry_date', $organizationProfile['company_expiry_date'] ?? null, 10) ?></label>
        <label>شماره ثابت <input type="text" name="landline_phone" class="ltr" value="<?= e((string)$organizationProfile['landline_phone']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>شماره فکس <input type="text" name="fax_number" class="ltr" value="<?= e((string)$organizationProfile['fax_number']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      </div>
    </div>

    <div class="subsection">
      <h3 class="subsection-title">اطلاعات مدیرعامل / نماینده شرکت</h3>
      <div class="grid grid-2">
        <label>نام مدیرعامل شرکت <input type="text" name="ceo_name" value="<?= e((string)$organizationProfile['ceo_name']) ?>" maxlength="160" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>نام خانوادگی مدیرعامل شرکت <input type="text" name="ceo_last_name" value="<?= e((string)$organizationProfile['ceo_last_name']) ?>" maxlength="120" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>نام پدر مدیرعامل شرکت <input type="text" name="ceo_father_name" value="<?= e((string)$organizationProfile['ceo_father_name']) ?>" maxlength="120" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>کد ملی مدیرعامل شرکت <input type="text" name="ceo_national_code" class="ltr" value="<?= e((string)$organizationProfile['ceo_national_code']) ?>" maxlength="20" placeholder="۱۰ رقم" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>شماره شناسنامه مدیرعامل شرکت <input type="text" name="ceo_birth_certificate_no" class="ltr" value="<?= e((string)$organizationProfile['ceo_birth_certificate_no']) ?>" maxlength="30" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>تاریخ تولد مدیرعامل شرکت <?= jalali_date_select('ceo_birth_date', $organizationProfile['ceo_birth_date'] ?? null) ?></label>
        <label>شهر محل تولد مدیرعامل شرکت <input type="text" name="ceo_birth_city" value="<?= e((string)$organizationProfile['ceo_birth_city']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>موبایل مدیرعامل <input type="text" name="ceo_mobile" class="ltr" value="<?= e((string)$organizationProfile['ceo_mobile']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>ایمیل مدیرعامل <input type="text" name="ceo_email" class="ltr" value="<?= e((string)$organizationProfile['ceo_email']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
        <label>کاربر مرتبط (اختیاری)
          <select name="legal_representative_user_id" <?= $canManageOrganization ? '' : 'disabled' ?>>
            <option value="0">—</option>
            <option value="<?= (int)$me['id'] ?>"<?= (int)($organizationProfile['legal_representative_user_id'] ?? 0) === (int)$me['id'] ? ' selected' : '' ?>><?= e((string)$me['username']) ?></option>
          </select>
        </label>
      </div>
    </div>

    <?php if ($canManageOrganization && !$profileReadOnly): ?>
      <button class="btn btn-primary" style="margin-top:6px">ذخیره‌ی اطلاعات شرکت و نماینده</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if ($organizationId > 0): ?>
<div class="card">
  <h2><?= $accountType === 'legal' ? 'آدرس شرکت' : 'آدرس و تماس' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_address">
    <div class="grid grid-2">
      <label>استان <input type="text" name="province" value="<?= e((string)$address['province']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>شهر <input type="text" name="city" value="<?= e((string)$address['city']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>خیابان <input type="text" name="street" value="<?= e((string)$address['street']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کوچه <input type="text" name="alley" value="<?= e((string)$address['alley']) ?>" maxlength="120" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>پلاک <input type="text" name="building_no" class="ltr" value="<?= e((string)$address['building_no']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>واحد <input type="text" name="unit_no" class="ltr" value="<?= e((string)$address['unit_no']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کد پستی <input type="text" name="postal_code" class="ltr" value="<?= e((string)$address['postal_code']) ?>" maxlength="20" placeholder="۱۰ رقم" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>منطقه / محله <input type="text" name="district" value="<?= e((string)$address['district']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کشور <input type="text" name="country" value="<?= e((string)$address['country']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label style="grid-column:1/-1">آدرس کامل (توضیح تکمیلی) <input type="text" name="address_text" value="<?= e((string)($address['address_text'] ?? '')) ?>" maxlength="500" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
    </div>
    <?php if ($canManageOrganization && !$profileReadOnly): ?>
      <button class="btn btn-primary" style="margin-top:12px">ذخیره‌ی آدرس</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php
// Document tiles: one card per owner (user/organization), a dedicated tile per catalog document
// type — including types not yet uploaded, so the customer sees the full expected checklist at a
// glance rather than only what happens to already exist (§F of docs/profile-kyc.md's UI spec).
$docCards = [];
if ($accountType === 'legal' && $organizationId > 0) {
    $docCards[] = ['title' => 'مدارک هویتی نماینده', 'owner' => 'user', 'types' => PROFILE_USER_DOCUMENT_TYPES, 'byType' => $activeUserDocsByType, 'editable' => !$profileReadOnly];
    $docCards[] = ['title' => 'مدارک شرکت', 'owner' => 'organization', 'types' => PROFILE_ORGANIZATION_DOCUMENT_TYPES, 'byType' => $activeOrgDocsByType, 'editable' => $canManageOrganization && !$profileReadOnly];
} else {
    $docCards[] = ['title' => 'مدارک احراز هویت', 'owner' => 'user', 'types' => PROFILE_USER_DOCUMENT_TYPES, 'byType' => $activeUserDocsByType, 'editable' => !$profileReadOnly];
}
?>
<?php foreach ($docCards as $card): ?>
<div class="card">
  <h2><?= e($card['title']) ?></h2>
  <div class="doc-grid">
    <?php foreach ($card['types'] as $type => $label):
        $tileType = $type; $tileLabel = $label; $tileDocument = $card['byType'][$type] ?? null;
        $tileEditable = $card['editable']; $tileOwnerField = $card['owner']; $tileReviewBadge = $reviewBadgeClass;
        include __DIR__ . '/../app/views/partials/profile_doc_tile.php';
    endforeach; ?>
  </div>
  <p class="hint" style="margin-top:14px">فرمت‌های مجاز: JPG، PNG، WEBP، PDF — حداکثر ۸ مگابایت. بارگذاری یک فایل جدید برای همان نوع مدرک، نسخه‌ی قبلی را بایگانی و وضعیت بررسی را به «در انتظار بررسی» بازمی‌گرداند.</p>
</div>
<?php endforeach; ?>

<div class="card">
  <h2>تغییر رمز عبور</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <div class="grid grid-2">
      <label>رمز عبور فعلی <input type="password" name="current" required></label>
      <label>رمز عبور جدید <input type="password" name="new" minlength="6" required></label>
      <label>تکرار رمز عبور جدید <input type="password" name="repeat" minlength="6" required></label>
    </div>
    <?php if (!$profileReadOnly): ?>
      <button class="btn btn-primary">تغییر رمز عبور</button>
    <?php endif; ?>
  </form>
</div>

<?php if ($organizationId > 0): ?>
<div class="card">
  <h2>هشدار اعتبار کم</h2>
  <p class="hint">آستانه بر حسب «واحد اعتبار» است و فقط یک تنظیم است؛ موجودی کیف پول را تغییر نمی‌دهد.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_notifications">
    <div class="grid grid-2">
      <label><input type="checkbox" name="low_credit_alert_enabled" value="1"<?= $notifications['low_credit_alert_enabled'] ? ' checked' : '' ?> <?= $canManageOrganization ? '' : 'disabled' ?>> هشدار اعتبار کم فعال باشد</label>
      <label>آستانه‌ی اعتبار کم <input type="text" name="low_credit_threshold" class="ltr" value="<?= e((string)$notifications['low_credit_threshold']) ?>" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label><input type="checkbox" name="email_alert_enabled" value="1"<?= $notifications['email_alert_enabled'] ? ' checked' : '' ?> <?= $canManageOrganization ? '' : 'disabled' ?>> اعلان ایمیلی</label>
      <label><input type="checkbox" name="sms_alert_enabled" value="1"<?= $notifications['sms_alert_enabled'] ? ' checked' : '' ?> <?= $canManageOrganization ? '' : 'disabled' ?>> اعلان پیامکی</label>
      <label>ایمیل اعلان <input type="text" name="alert_email" class="ltr" value="<?= e((string)$notifications['alert_email']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>موبایل اعلان <input type="text" name="alert_mobile" class="ltr" value="<?= e((string)$notifications['alert_mobile']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
    </div>
    <?php if ($canManageOrganization && !$profileReadOnly): ?>
      <button class="btn btn-primary">ذخیره‌ی تنظیمات</button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>آدرس‌های IP مجاز</h2>
  <p class="hint">این فهرست فقط برای ثبت و مدیریت است؛ اعمال محدودیت ورود بر اساس IP در حال حاضر در این نسخه فعال نیست (docs/profile-kyc.md).</p>
  <div class="table-wrap">
  <table>
    <tr><th>آدرس / محدوده</th><th>برچسب</th><th>وضعیت</th><th>تاریخ ثبت</th><th></th></tr>
    <?php foreach ($allowedIps as $ip): ?>
      <tr>
        <td class="ltr"><?= e((string)$ip['ip_or_cidr']) ?></td>
        <td><?= e((string)$ip['label']) ?: '—' ?></td>
        <td><span class="badge <?= $ip['status'] === 'active' ? 'badge-ok' : 'badge-off' ?>"><?= $ip['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span></td>
        <td><?= e(jdate((string)$ip['created_at'])) ?></td>
        <td>
          <?php if ($canManageOrganization && !$profileReadOnly): ?>
          <div class="toolbar" style="margin:0">
            <form method="post" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="allowed_ip_toggle">
              <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
              <button class="btn btn-sm"><?= $ip['status'] === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('این آدرس حذف شود؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="allowed_ip_delete">
              <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
              <button class="btn btn-sm">حذف</button>
            </form>
          </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$allowedIps): ?><tr><td colspan="5" class="empty">آدرسی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($canManageOrganization && !$profileReadOnly): ?>
    <form method="post" class="toolbar" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="allowed_ip_create">
      <label>آدرس IP یا CIDR <input type="text" name="ip_or_cidr" class="ltr" placeholder="203.0.113.10 یا 203.0.113.0/24" required></label>
      <label>برچسب <input type="text" name="label" maxlength="120" placeholder="مثلاً دفتر مرکزی"></label>
      <button class="btn btn-primary btn-sm">افزودن</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
