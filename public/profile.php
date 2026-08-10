<?php
/**
 * ELLSMS — the user's own account page: password, personal profile, and (for members of an
 * organization) the company profile, address, low-credit alerts and documents
 * (docs/customer-profile.md).
 *
 * EDIT POLICY, enforced server-side on every branch below:
 *   - personal profile and personal documents: always the user's own, no permission needed;
 *   - company profile / address / alerts / organization documents: `settings.manage` only, i.e.
 *     owner and admin — an ordinary member READS them and cannot change the company's legal record.
 *
 * The organization shown is the ACTIVE one (current_organization()). A user who belongs to two
 * organizations sees the company profile of whichever is active and never a merge of the two.
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
        'profile_organization'  => 'profile.organization',
        'profile_address'       => 'profile.organization',
        'profile_notifications' => 'profile.organization',
        'document_upload'       => 'profile.documents',
        'document_archive'      => 'profile.documents',
    ][$do] ?? null;
    if ($impersonationAction !== null && impersonation_guard_post($impersonationAction)) {
        redirect('/profile.php');
    }

    // Every organization-scoped branch needs the same two things: an active organization and
    // settings.manage. Checked once, here, rather than repeated (and eventually forgotten) below.
    $organizationScoped = in_array($do, ['profile_organization', 'profile_address', 'profile_notifications'], true)
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
            'father_name'          => $_POST['father_name'] ?? '',
            'national_code'        => $_POST['national_code'] ?? '',
            'birth_certificate_no' => $_POST['birth_certificate_no'] ?? '',
            'birth_date'           => profile_date_from_request('birth_date'),
            'gender'               => $_POST['gender'] ?? 'unspecified',
            'personal_address'     => $_POST['personal_address'] ?? '',
        ], (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'اطلاعات فردی ذخیره شد.'
            : profile_error_message((string)$result['reason']));
    } elseif ($do === 'profile_organization') {
        $result = profile_organization_save($organizationId, [
            'legal_name'                   => $_POST['legal_name'] ?? '',
            'company_type'                 => $_POST['company_type'] ?? 'unspecified',
            'registration_number'          => $_POST['registration_number'] ?? '',
            'national_id'                  => $_POST['national_id'] ?? '',
            'economic_code'                => $_POST['economic_code'] ?? '',
            'ceo_name'                     => $_POST['ceo_name'] ?? '',
            'ceo_father_name'              => $_POST['ceo_father_name'] ?? '',
            'ceo_national_code'            => $_POST['ceo_national_code'] ?? '',
            'ceo_birth_date'               => profile_date_from_request('ceo_birth_date'),
            'company_start_date'           => profile_date_from_request('company_start_date'),
            'company_expiry_date'          => profile_date_from_request('company_expiry_date'),
            'legal_representative_user_id' => $_POST['legal_representative_user_id'] ?? 0,
        ], (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'اطلاعات سازمان ذخیره شد.'
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
    }

    redirect('/profile.php');
}

$userProfile   = profile_user_get((int)$me['id']);
$userDocuments = profile_documents_list(['user' => (int)$me['id']]);
$userScore     = profile_user_completeness($userProfile);

$organizationProfile = $organizationId > 0 ? profile_organization_get($organizationId) : null;
$address             = $organizationId > 0 ? profile_address_get($organizationId) : null;
$notifications       = $organizationId > 0 ? profile_notifications_get($organizationId) : null;
$organizationDocuments = $organizationId > 0 ? profile_documents_list(['organization' => $organizationId]) : [];
$organizationScore   = $organizationId > 0 ? profile_organization_completeness($organizationProfile, $address) : null;

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'profile.personal';
require __DIR__ . '/../app/views/impersonation_notice.php';
$profileReadOnly = is_impersonating();
?>

<div class="card">
  <h2>اطلاعات حساب</h2>
  <p class="hint">این مقادیر در سامانه‌ی مرکزی نگهداری می‌شوند و از این صفحه قابل ویرایش نیستند.</p>
  <div class="table-wrap">
  <table>
    <tr><th>نام کاربری</th><td class="ltr"><?= e((string)$me['username']) ?></td></tr>
    <tr><th>نام و نام خانوادگی</th><td><?= e(trim((string)$me['first_name'] . ' ' . (string)$me['last_name'])) ?: '—' ?></td></tr>
    <tr><th>موبایل</th><td class="msisdn"><?= e((string)$me['mobile']) ?: '—' ?></td></tr>
    <tr><th>ایمیل</th><td class="ltr"><?= e((string)$me['email']) ?: '—' ?></td></tr>
  </table>
  </div>
</div>

<div class="card">
  <h2>اطلاعات فردی <span class="hint" style="display:inline">— تکمیل‌شده: <?= to_persian_digits((string)$userScore['percent']) ?>٪</span></h2>
  <?php if (!empty($userProfile['from_legacy_kyc'])): ?>
    <div class="flash flash-info">بخشی از این اطلاعات از بخش «اطلاعات هویتی» قبلی خوانده شده است و با نخستین ذخیره‌سازی به‌روزرسانی می‌شود.</div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_personal">
    <div class="grid grid-2">
      <label>نام پدر <input type="text" name="father_name" value="<?= e((string)$userProfile['father_name']) ?>" maxlength="120"></label>
      <label>کد ملی <input type="text" name="national_code" class="ltr" value="<?= e((string)$userProfile['national_code']) ?>" maxlength="20" placeholder="۱۰ رقم"></label>
      <label>شماره شناسنامه <input type="text" name="birth_certificate_no" class="ltr" value="<?= e((string)$userProfile['birth_certificate_no']) ?>" maxlength="30"></label>
      <label>جنسیت
        <select name="gender">
          <?php foreach (PROFILE_GENDERS as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($userProfile['gender'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>تاریخ تولد <?= jalali_date_select('birth_date', $userProfile['birth_date'] ?? null) ?></label>
      <label>آدرس شخصی <input type="text" name="personal_address" value="<?= e((string)($userProfile['personal_address'] ?? '')) ?>" maxlength="500"></label>
    </div>
    <?php if (!$profileReadOnly): ?>
      <button class="btn btn-primary">ذخیره‌ی اطلاعات فردی</button>
    <?php endif; ?>
  </form>
  <?php if ($userScore['missing']): ?>
    <p class="hint">تکمیل‌نشده: <?= e(implode('، ', $userScore['missing'])) ?></p>
  <?php endif; ?>
</div>

<?php if ($organizationId > 0): ?>
<div class="card">
  <h2>اطلاعات سازمان — <?= e((string)$organization['name']) ?>
    <span class="hint" style="display:inline">— تکمیل‌شده: <?= to_persian_digits((string)$organizationScore['percent']) ?>٪</span>
  </h2>
  <?php if (!$canManageOrganization): ?>
    <p class="hint">شما به این اطلاعات دسترسی مشاهده دارید؛ ویرایش آن‌ها نیازمند دسترسی مدیریت تنظیمات سازمان است.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_organization">
    <div class="grid grid-2">
      <label>نام حقوقی <input type="text" name="legal_name" value="<?= e((string)$organizationProfile['legal_name']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>نوع شرکت
        <select name="company_type" <?= $canManageOrganization ? '' : 'disabled' ?>>
          <?php foreach (PROFILE_COMPANY_TYPES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($organizationProfile['company_type'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>شماره ثبت <input type="text" name="registration_number" class="ltr" value="<?= e((string)$organizationProfile['registration_number']) ?>" maxlength="40" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>شناسه ملی شرکت <input type="text" name="national_id" class="ltr" value="<?= e((string)$organizationProfile['national_id']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کد اقتصادی <input type="text" name="economic_code" class="ltr" value="<?= e((string)$organizationProfile['economic_code']) ?>" maxlength="30" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>مدیرعامل <input type="text" name="ceo_name" value="<?= e((string)$organizationProfile['ceo_name']) ?>" maxlength="160" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>نام پدر مدیرعامل <input type="text" name="ceo_father_name" value="<?= e((string)$organizationProfile['ceo_father_name']) ?>" maxlength="120" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کد ملی مدیرعامل <input type="text" name="ceo_national_code" class="ltr" value="<?= e((string)$organizationProfile['ceo_national_code']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>تاریخ تولد مدیرعامل <?= jalali_date_select('ceo_birth_date', $organizationProfile['ceo_birth_date'] ?? null) ?></label>
      <label>تاریخ شروع فعالیت <?= jalali_date_select('company_start_date', $organizationProfile['company_start_date'] ?? null) ?></label>
      <label>تاریخ انقضا <?= jalali_date_select('company_expiry_date', $organizationProfile['company_expiry_date'] ?? null, 10) ?></label>
    </div>
    <?php if ($canManageOrganization && !$profileReadOnly): ?>
      <button class="btn btn-primary">ذخیره‌ی اطلاعات سازمان</button>
    <?php endif; ?>
  </form>
  <?php if ($organizationScore['missing']): ?>
    <p class="hint">تکمیل‌نشده: <?= e(implode('، ', $organizationScore['missing'])) ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>آدرس سازمان</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_address">
    <div class="grid grid-2">
      <label>کشور <input type="text" name="country" value="<?= e((string)$address['country']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>استان <input type="text" name="province" value="<?= e((string)$address['province']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>شهر <input type="text" name="city" value="<?= e((string)$address['city']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>منطقه / محله <input type="text" name="district" value="<?= e((string)$address['district']) ?>" maxlength="60" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>خیابان <input type="text" name="street" value="<?= e((string)$address['street']) ?>" maxlength="190" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کوچه <input type="text" name="alley" value="<?= e((string)$address['alley']) ?>" maxlength="120" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>پلاک <input type="text" name="building_no" class="ltr" value="<?= e((string)$address['building_no']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>واحد <input type="text" name="unit_no" class="ltr" value="<?= e((string)$address['unit_no']) ?>" maxlength="20" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>کد پستی <input type="text" name="postal_code" class="ltr" value="<?= e((string)$address['postal_code']) ?>" maxlength="20" placeholder="۱۰ رقم" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
      <label>توضیح آدرس <input type="text" name="address_text" value="<?= e((string)($address['address_text'] ?? '')) ?>" maxlength="500" <?= $canManageOrganization ? '' : 'disabled' ?>></label>
    </div>
    <?php if ($canManageOrganization && !$profileReadOnly): ?>
      <button class="btn btn-primary">ذخیره‌ی آدرس</button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>تنظیمات اعلان اعتبار</h2>
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
<?php endif; ?>

<?php
// Both document sections share one presentation; only the owner and the permitted type list differ.
$documentSections = [
    ['owner' => 'user', 'title' => 'مدارک فردی', 'types' => PROFILE_USER_DOCUMENT_TYPES, 'documents' => $userDocuments, 'editable' => !$profileReadOnly],
];
if ($organizationId > 0) {
    $documentSections[] = ['owner' => 'organization', 'title' => 'مدارک سازمان', 'types' => PROFILE_ORGANIZATION_DOCUMENT_TYPES, 'documents' => $organizationDocuments, 'editable' => $canManageOrganization && !$profileReadOnly];
}
?>
<?php foreach ($documentSections as $section): ?>
<div class="card">
  <h2><?= e($section['title']) ?></h2>
  <div class="table-wrap">
  <table>
    <tr><th>نوع مدرک</th><th>وضعیت</th><th>تاریخ بارگذاری</th><th></th></tr>
    <?php foreach ($section['documents'] as $document): ?>
      <tr>
        <td><?= e(profile_document_type_label((string)$document['document_type'])) ?></td>
        <td><span class="badge badge-<?= $document['status'] === 'active' ? 'ok' : 'off' ?>"><?= $document['status'] === 'active' ? 'فعال' : 'بایگانی' ?></span></td>
        <td><?= e(jdate((string)$document['created_at'])) ?></td>
        <td>
          <div class="toolbar" style="margin:0">
            <a class="btn btn-sm" href="/profile-document.php?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener">مشاهده</a>
            <?php if ($section['editable'] && $document['status'] === 'active'): ?>
              <form method="post" style="margin:0" onsubmit="return confirm('این مدرک بایگانی شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="document_archive">
                <input type="hidden" name="owner" value="<?= e($section['owner']) ?>">
                <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                <button class="btn btn-sm">بایگانی</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$section['documents']): ?><tr><td colspan="4" class="empty">هنوز مدرکی بارگذاری نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($section['editable']): ?>
    <form method="post" enctype="multipart/form-data" class="toolbar" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="document_upload">
      <input type="hidden" name="owner" value="<?= e($section['owner']) ?>">
      <label>نوع مدرک
        <select name="document_type">
          <?php foreach ($section['types'] as $value => $label): ?>
            <option value="<?= e($value) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>فایل <input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf" required></label>
      <button class="btn btn-primary btn-sm">بارگذاری</button>
    </form>
    <p class="hint">فرمت‌های مجاز: JPG، PNG، WEBP، PDF — حداکثر ۸ مگابایت. بارگذاری مدرک جدید از همین نوع، نسخه‌ی قبلی را بایگانی می‌کند.</p>
  <?php endif; ?>
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
<?php require __DIR__ . '/../app/views/footer.php'; ?>
