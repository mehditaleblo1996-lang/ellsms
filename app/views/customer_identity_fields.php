<?php
/**
 * مشخصات مشتری — one form for everything about who the customer is, shared by «ساخت حساب تازه» and
 * the edit page of public/users.php (it replaced the separate personal / organization / address /
 * KYC tabs).
 *
 * The «حقیقی / حقوقی» switch at the top decides which part is filled: the person's own details always,
 * and the company's details (with its managing director) only for a legal account. The company part
 * is a <fieldset> that the switch disables while «حقیقی» is chosen, so its fields are neither required
 * nor submitted then — and on the edit page an individual's save therefore never blanks company data
 * kept from an earlier «حقوقی» period (profile_organization_save() is merge-safe on absent keys).
 *
 * expects: $idf = [
 *   'mode'         => 'create' | 'edit',
 *   'account_type' => 'individual' | 'legal',
 *   'user'         => profile_user_get() row (edit) or [] (create),
 *   'org'          => profile_organization_get() row (edit) or [] (create),
 *   'address'      => profile_address_get() row (edit) or [] (create),
 *   'id'           => a short unique prefix for element ids,
 * ]
 */
$idfMode    = $idf['mode'];
$idfCreate  = $idfMode === 'create';
$idfType    = ($idf['account_type'] ?? 'individual') === 'legal' ? 'legal' : 'individual';
$idfUser    = $idf['user'] ?? [];
$idfOrg     = $idf['org'] ?? [];
$idfAddress = $idf['address'] ?? [];
$idfId      = (string)$idf['id'];
$idfVal     = static fn(array $row, string $key): string => e((string)($row[$key] ?? ''));
$req        = '<span class="req">*</span>';
// Required only when the account is being made; an older account being edited may still lack some
// of these, and that must not stop the admin from saving the rest.
$must       = $idfCreate ? $req : '';
$mustAttr   = $idfCreate ? ' required' : '';
?>
<div class="form-section" data-account-type-switch>
  <div class="field">
    <span class="field-label" id="<?= e($idfId) ?>_type_label">نوع حساب <?= $req ?></span>
    <div class="segmented" role="radiogroup" aria-labelledby="<?= e($idfId) ?>_type_label">
      <?php foreach (PROFILE_ACCOUNT_TYPES as $value => $label): ?>
        <input type="radio" id="<?= e($idfId) ?>_type_<?= e($value) ?>" name="account_type" value="<?= e($value) ?>"<?= $value === $idfType ? ' checked' : '' ?>>
        <label for="<?= e($idfId) ?>_type_<?= e($value) ?>"><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <span class="hint">برای حساب حقوقی، مشخصات شرکت و مدیرعامل هم پرسیده می‌شود.</span>
  </div>
</div>

<div class="form-section">
  <h3 class="subsection-title" data-when-type="individual"<?= $idfType === 'legal' ? ' hidden' : '' ?>>مشخصات فردی</h3>
  <h3 class="subsection-title" data-when-type="legal"<?= $idfType === 'legal' ? '' : ' hidden' ?>>مشخصات نماینده‌ی شرکت (صاحب حساب)</h3>
  <div class="form-grid cols-4">
    <?php if ($idfCreate): ?>
      <label>نام <?= $req ?> <input type="text" name="first_name" required maxlength="100"></label>
      <label>نام‌خانوادگی <?= $req ?> <input type="text" name="last_name" required maxlength="100"></label>
    <?php endif; ?>
    <label>کد ملی <?= $must ?> <input type="text" name="national_code" class="ltr" inputmode="numeric" value="<?= $idfVal($idfUser, 'national_code') ?>" maxlength="10" placeholder="۱۰ رقم"<?= $mustAttr ?>></label>
    <label>نام پدر <input type="text" name="father_name" value="<?= $idfVal($idfUser, 'father_name') ?>" maxlength="120"></label>
    <label>شماره شناسنامه <input type="text" name="birth_certificate_no" class="ltr" inputmode="numeric" value="<?= $idfVal($idfUser, 'birth_certificate_no') ?>" maxlength="30"></label>
    <label>تاریخ تولد <?= jalali_date_select('birth_date', $idfUser['birth_date'] ?? null, yearsAhead: 0, yearsBack: 100, allowEmpty: true) ?></label>
    <label>جنسیت
      <select name="gender">
        <?php foreach (PROFILE_GENDERS as $value => $label): ?>
          <?php if ($idfCreate && $value === 'unspecified') continue; // the central system takes male/female only ?>
          <option value="<?= e($value) ?>"<?= ($idfUser['gender'] ?? ($idfCreate ? 'male' : 'unspecified')) === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
</div>

<?php if ($idfCreate): ?>
<div class="form-section">
  <h3 class="subsection-title">اطلاعات تماس</h3>
  <div class="form-grid cols-4">
    <label>موبایل <?= $req ?> <input type="text" name="mobile" required class="ltr" inputmode="tel" placeholder="0912…"></label>
    <label class="span-2">ایمیل <?= $req ?> <input type="email" name="email" required class="ltr"></label>
  </div>
</div>
<?php endif; ?>

<fieldset class="form-section" data-when-type="legal"<?= $idfType === 'legal' ? '' : ' hidden disabled' ?>>
  <h3 class="subsection-title">اطلاعات شرکت</h3>
  <div class="form-grid cols-4">
    <label class="span-2">نام شرکت (نام حقوقی) <?= $must ?> <input type="text" name="legal_name" value="<?= $idfVal($idfOrg, 'legal_name') ?>" maxlength="190"<?= $mustAttr ?>></label>
    <label>نوع شرکت
      <select name="company_type">
        <?php foreach (PROFILE_COMPANY_TYPES as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($idfOrg['company_type'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>شماره ثبت <?= $must ?> <input type="text" name="registration_number" class="ltr" inputmode="numeric" value="<?= $idfVal($idfOrg, 'registration_number') ?>" maxlength="40"<?= $mustAttr ?>></label>
    <label>شناسه ملی شرکت <?= $must ?> <input type="text" name="company_national_id" class="ltr" inputmode="numeric" value="<?= $idfVal($idfOrg, 'national_id') ?>" maxlength="20"<?= $mustAttr ?>></label>
    <label>کد اقتصادی <?= $must ?> <input type="text" name="economic_code" class="ltr" inputmode="numeric" value="<?= $idfVal($idfOrg, 'economic_code') ?>" maxlength="30"<?= $mustAttr ?>></label>
    <label>تلفن ثابت <input type="text" name="landline_phone" class="ltr" inputmode="tel" value="<?= $idfVal($idfOrg, 'landline_phone') ?>" maxlength="20"></label>
    <label>تاریخ شروع فعالیت <?= jalali_date_select('company_start_date', $idfOrg['company_start_date'] ?? null, yearsAhead: 0, yearsBack: 100, allowEmpty: true) ?></label>
    <label>تاریخ انقضا <?= jalali_date_select('company_expiry_date', $idfOrg['company_expiry_date'] ?? null, yearsAhead: 15, yearsBack: 10, allowEmpty: true) ?></label>
  </div>
  <h3 class="subsection-title">مدیرعامل</h3>
  <div class="form-grid cols-4">
    <label>نام و نام‌خانوادگی مدیرعامل <?= $must ?> <input type="text" name="ceo_name" value="<?= $idfVal($idfOrg, 'ceo_name') ?>" maxlength="160"<?= $mustAttr ?>></label>
    <label>نام پدر مدیرعامل <input type="text" name="ceo_father_name" value="<?= $idfVal($idfOrg, 'ceo_father_name') ?>" maxlength="120"></label>
    <label>کد ملی مدیرعامل <input type="text" name="ceo_national_code" class="ltr" inputmode="numeric" value="<?= $idfVal($idfOrg, 'ceo_national_code') ?>" maxlength="10" placeholder="۱۰ رقم"></label>
    <label>تاریخ تولد مدیرعامل <?= jalali_date_select('ceo_birth_date', $idfOrg['ceo_birth_date'] ?? null, yearsAhead: 0, yearsBack: 100, allowEmpty: true) ?></label>
  </div>
</fieldset>

<div class="form-section">
  <h3 class="subsection-title" data-when-type="individual"<?= $idfType === 'legal' ? ' hidden' : '' ?>>آدرس</h3>
  <h3 class="subsection-title" data-when-type="legal"<?= $idfType === 'legal' ? '' : ' hidden' ?>>آدرس شرکت</h3>
  <div class="form-grid cols-4">
    <label>کشور <?= geo_country_select('country', (string)(($idfAddress['country'] ?? '') ?: GEO_DEFAULT_COUNTRY)) ?></label>
    <label>استان <?= geo_province_select('province', (string)($idfAddress['province'] ?? '')) ?></label>
    <label>شهر <?= geo_city_select('city', (string)($idfAddress['city'] ?? ''), (string)($idfAddress['province'] ?? '')) ?></label>
    <label>منطقه / محله <input type="text" name="district" value="<?= $idfVal($idfAddress, 'district') ?>" maxlength="60"></label>
    <label class="span-2">خیابان <input type="text" name="street" value="<?= $idfVal($idfAddress, 'street') ?>" maxlength="190"></label>
    <label class="span-2">کوچه <input type="text" name="alley" value="<?= $idfVal($idfAddress, 'alley') ?>" maxlength="120"></label>
    <label>پلاک <input type="text" name="building_no" class="ltr" value="<?= $idfVal($idfAddress, 'building_no') ?>" maxlength="20"></label>
    <label>واحد <input type="text" name="unit_no" class="ltr" value="<?= $idfVal($idfAddress, 'unit_no') ?>" maxlength="20"></label>
    <label class="span-2">کد پستی <input type="text" name="postal_code" class="ltr" inputmode="numeric" value="<?= $idfVal($idfAddress, 'postal_code') ?>" maxlength="10" placeholder="۱۰ رقم"></label>
    <label class="span-full">نشانی کامل / توضیح <input type="text" name="address_text" value="<?= $idfVal($idfAddress, 'address_text') ?>" maxlength="500"></label>
  </div>
</div>
