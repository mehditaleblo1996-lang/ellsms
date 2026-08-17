<?php
/**
 * ELLSMS — customer/organization profile: personal profile, company legal profile, address,
 * low-credit notification preferences, and private profile documents (docs/customer-profile.md).
 *
 * THE ONE RULE THIS FILE EXISTS TO ENFORCE:
 *
 *   personal identity data belongs to the USER; company/legal data belongs to the ORGANIZATION.
 *
 * Company data keyed by user_id would make two things impossible that this product genuinely needs:
 * a second member of an organization seeing the same company profile, and one user in two
 * organizations seeing two different ones. So every company read/write below takes an
 * organization_id, and every personal one takes a user_id — there is no function that takes both
 * and guesses.
 *
 * SOURCE OF TRUTH. The backend platform owns identity (username, first/last name, email, mobile,
 * currentcredit) and stays authoritative for all of it; none of it is copied into these tables, and
 * nothing here writes to `user_`. This file holds only what the backend does not own. The UI renders
 * backend-owned values read-only, labelled as coming from the central system.
 *
 * DOCUMENTS. Files live outside the web root, are named from opaque random bytes (never anything
 * user-supplied), are validated by real MIME inspection, and are reachable only through
 * public/profile-document.php, which authorizes every read. Replacement archives rather than
 * overwrites, so a document's history survives.
 */

declare(strict_types=1);

/* ==========================================================================
   Catalogs (STEP 7/15/32)
   ========================================================================== */

/**
 * Account type — durable, organization-profile-level (never a scattered per-feature flag). Every
 * organization has exactly one: حقیقی (individual) or حقوقی (legal). Existing organizations backfill
 * to 'individual' unless their existing data already looks like a company (db/migrations/
 * 2026_08_17_kyc_workflow.sql), so no production organization is silently reclassified as legal.
 */
const PROFILE_ACCOUNT_TYPES = [
    'individual' => 'حقیقی',
    'legal'      => 'حقوقی',
];

function profile_account_type_label(string $type): string {
    return PROFILE_ACCOUNT_TYPES[$type] ?? PROFILE_ACCOUNT_TYPES['individual'];
}

/**
 * Company types — ONE centralized catalog (§5 of the KYC phase brief: "Do not hard-code display
 * labels in multiple places"). The original five values (`legal_entity` … `unspecified`) predate this
 * phase and are kept exactly as-is for backward compatibility with rows already saved against them;
 * the Iranian legal-entity types below are ADDITIVE, widened into the same database ENUM by
 * db/migrations/2026_08_17_kyc_workflow.sql so no existing row's value ever needs rewriting.
 * `unspecified` remains the default — not every organization is a company.
 */
const PROFILE_COMPANY_TYPES = [
    'legal_entity'         => 'شخص حقوقی',
    'individual_business'  => 'کسب‌وکار انفرادی',
    'government'           => 'دولتی / عمومی',
    'private_joint_stock'  => 'سهامی خاص',
    'public_joint_stock'   => 'سهامی عام',
    'limited_liability'    => 'مسئولیت محدود',
    'cooperative'          => 'تعاونی',
    'institution'          => 'مؤسسه',
    'governmental'         => 'دولتی',
    'other'                => 'سایر',
    'unspecified'          => 'نامشخص',
];

function profile_company_type_label(string $type): string {
    return PROFILE_COMPANY_TYPES[$type] ?? $type;
}

const PROFILE_GENDERS = [
    'male'        => 'مرد',
    'female'      => 'زن',
    'unspecified' => 'نامشخص',
];

/**
 * Document types, split by the domain that owns them. The split is not cosmetic: it is what stops a
 * company document being filed against a person (or the reverse), which would then be visible to
 * the wrong audience.
 */
const PROFILE_USER_DOCUMENT_TYPES = [
    'national_card'               => 'کارت ملی',
    'birth_certificate'           => 'شناسنامه',
    'selfie_with_national_card'   => 'سلفی با کارت ملی',
    'address_proof'               => 'مدرک آدرس محل سکونت',
];

const PROFILE_ORGANIZATION_DOCUMENT_TYPES = [
    'incorporation_notice'         => 'آگهی تأسیس',
    'latest_changes_notice'        => 'آگهی آخرین تغییرات',
    'registration_document'        => 'سند ثبت شرکت',
    'introduction_letter'          => 'معرفی‌نامه',
    'postal_certificate'           => 'تأییدیه کد پستی',
    'representative_national_card' => 'کارت ملی نماینده قانونی',
];

/**
 * The MINIMUM document set §16 requires before a KYC request may be submitted, by account type.
 * Deliberately a small, product-defined subset of the full catalogs above — every catalog entry is
 * always uploadable, but only these are BLOCKING for submission (app/Kyc.php's kyc_can_submit()).
 */
const PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL = ['national_card', 'selfie_with_national_card'];
const PROFILE_REQUIRED_DOCUMENTS_LEGAL = ['incorporation_notice', 'representative_national_card'];

/**
 * Fields a LEGAL ENTITY is expected to provide, used only by the completeness figure and the
 * integrity report. Deliberately not enforced at save time (STEP 32): a half-filled profile that can
 * be saved and finished later is far more useful than a form that refuses until every box is full.
 */
const PROFILE_LEGAL_ENTITY_REQUIRED_FIELDS = [
    'legal_name', 'registration_number', 'national_id', 'economic_code', 'ceo_name',
];

function profile_document_types_for(string $owner): array {
    return $owner === 'organization' ? PROFILE_ORGANIZATION_DOCUMENT_TYPES : PROFILE_USER_DOCUMENT_TYPES;
}

function profile_document_type_label(string $type): string {
    return PROFILE_USER_DOCUMENT_TYPES[$type] ?? PROFILE_ORGANIZATION_DOCUMENT_TYPES[$type] ?? $type;
}

/* ==========================================================================
   Document storage policy (STEP 16/17/18)
   ========================================================================== */

/**
 * Outside the web root, exactly like storage/kyc — a photo of someone's ID must never be reachable
 * by a guessed URL, only through the authorizing endpoint.
 */
function profile_document_dir(): string {
    return APP_ROOT . '/storage/profile-documents';
}

/** 8MB, matching the existing KYC limit rather than inventing a second number for the same kind of file. */
const PROFILE_DOCUMENT_MAX_BYTES = 8 * 1024 * 1024;

/**
 * Accepted formats, by REAL detected MIME type — never by the filename, which is attacker-controlled.
 * Mirrors KYC_ALLOWED_MIME deliberately: same threat, same answer. SVG is excluded (it is script-
 * bearing markup, not an image, and nothing here needs it); so is everything executable or archived.
 */
const PROFILE_DOCUMENT_ALLOWED_MIME = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

/* ==========================================================================
   Normalization & validation (STEP 5/10)
   ========================================================================== */

/** Persian/Arabic digits -> ASCII, non-digits stripped. Reuses the existing helper rather than a second copy. */
function profile_normalize_digits(string $raw): string {
    return preg_replace('/\D/', '', from_persian_digits(trim($raw))) ?? '';
}

/**
 * A national code is stored as 10 ASCII digits, or empty.
 *
 * NOT verified: this performs no checksum and contacts no government registry, so it never claims a
 * code is genuine — only that it is the right shape. Storing a well-formed-but-wrong code is a data
 * quality problem; claiming verification the product cannot perform would be a correctness lie.
 */
function profile_normalize_national_code(string $raw): string {
    $digits = profile_normalize_digits($raw);
    return strlen($digits) === 10 ? $digits : '';
}

/** Iranian postal codes are 10 digits; anything else is kept out rather than stored malformed. */
function profile_normalize_postal_code(string $raw): string {
    $digits = profile_normalize_digits($raw);
    return strlen($digits) === 10 ? $digits : '';
}

/** Collapses whitespace and bounds the length. Unicode-safe, so Persian names survive intact. */
function profile_clean_text(string $raw, int $maxLength): string {
    $clean = preg_replace('/\s+/u', ' ', strip_tags(trim($raw))) ?? '';
    return mb_substr($clean, 0, $maxLength);
}

/** A stored DATE, or null. Accepts the Jalali triple the existing date widget submits. */
function profile_date_from_request(string $field): ?string {
    return jalali_request_to_gregorian($field);
}

/**
 * Masks an identifier for logs and audit details (STEP 28/38): keeps enough to correlate, not enough
 * to be a copy of the value. `1234567890` -> `12******90`.
 */
function profile_mask_identifier(string $value): string {
    $value = trim($value);
    $length = strlen($value);
    if ($length === 0) {
        return '';
    }
    if ($length <= 4) {
        return str_repeat('*', $length);
    }
    return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
}

/* ==========================================================================
   Personal profile (Invariant A)
   ========================================================================== */

/**
 * The user's extended profile, always an array — an absent row reads as an empty profile, never null.
 *
 * LEGACY READ-THROUGH (STEP 52), deliberately narrow and explicit: `father_name` and
 * `personal_address` lived in `ellsms_user_kyc` before this feature. Until `make profile-backfill`
 * has run, this reads them from there so an operator who deploys the code without the backfill does
 * not see a customer's existing data vanish.
 *
 * It is READ-ONLY and there is exactly ONE write path: profile_user_save() writes only
 * ellsms_user_profiles, and once a row exists here the legacy table is never consulted again for
 * that user. So the two never diverge — a value is either not-yet-migrated or migrated, never
 * maintained in both. cron/profile-integrity-check.php reports how many users still depend on it.
 */
function profile_user_get(int $userId): array {
    $empty = [
        'user_id' => $userId, 'father_name' => '', 'national_code' => '', 'birth_certificate_no' => '',
        'birth_date' => null, 'national_id_expiry_at' => null, 'gender' => 'unspecified', 'personal_address' => null,
    ];
    if ($userId <= 0) {
        return $empty;
    }
    try {
        $st = db()->prepare('SELECT * FROM ellsms_user_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
        $legacy = db()->prepare('SELECT father_name, address FROM ellsms_user_kyc WHERE user_id = ?');
        $legacy->execute([$userId]);
        $kyc = $legacy->fetch();
        if ($kyc) {
            $empty['father_name'] = (string)($kyc['father_name'] ?? '');
            $empty['personal_address'] = $kyc['address'];
            $empty['from_legacy_kyc'] = true;
        }
        return $empty;
    } catch (Throwable $t) {
        Logger::error('profile.user_get_failed', ['user_id' => $userId, 'exception' => $t]);
        return $empty;
    }
}

/**
 * Saves the user's extended profile. Every value is normalized here rather than at the call site, so
 * the same rules apply whether the edit came from the self-service page or the admin page.
 *
 * $actorUserId is the REAL actor for the audit row; during impersonation that is the administrator
 * (audit() records both — see docs/admin-impersonation.md).
 */
function profile_user_save(int $userId, array $input, int $actorUserId): array {
    if ($userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_user'];
    }

    // Same merge-safe contract as profile_organization_save(): a key ABSENT from $input falls back to
    // whatever is already on file, so a caller that only touches some fields can never blank the rest.
    $previous = profile_user_get($userId);
    $input = $input + $previous;

    $gender = in_array($input['gender'] ?? '', array_keys(PROFILE_GENDERS), true) ? $input['gender'] : 'unspecified';
    $nationalCodeRaw = (string)($input['national_code'] ?? '');
    $nationalCode = profile_normalize_national_code($nationalCodeRaw);
    if ($nationalCodeRaw !== '' && $nationalCode === '') {
        return ['ok' => false, 'reason' => 'invalid_national_code'];
    }

    $values = [
        'father_name'           => profile_clean_text((string)($input['father_name'] ?? ''), 120),
        'national_code'         => $nationalCode,
        'birth_certificate_no'  => profile_normalize_digits((string)($input['birth_certificate_no'] ?? '')),
        'birth_date'            => $input['birth_date'] ?? null,
        'national_id_expiry_at' => $input['national_id_expiry_at'] ?? null,
        'gender'                => $gender,
        'personal_address'      => profile_clean_text((string)($input['personal_address'] ?? ''), 500),
    ];

    db()->prepare(
        'INSERT INTO ellsms_user_profiles
           (user_id, father_name, national_code, birth_certificate_no, birth_date, national_id_expiry_at, gender, personal_address)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           father_name = VALUES(father_name), national_code = VALUES(national_code),
           birth_certificate_no = VALUES(birth_certificate_no), birth_date = VALUES(birth_date),
           national_id_expiry_at = VALUES(national_id_expiry_at),
           gender = VALUES(gender), personal_address = VALUES(personal_address)'
    )->execute([
        $userId, $values['father_name'], $values['national_code'], $values['birth_certificate_no'],
        $values['birth_date'], $values['national_id_expiry_at'], $values['gender'], $values['personal_address'],
    ]);

    // The national code is MASKED in the audit detail: the trail must record that identity data
    // changed and for whom, not carry a second copy of the identifier itself (STEP 38).
    audit($actorUserId, 'profile.user_update', "user=#{$userId} national_code=" . profile_mask_identifier($values['national_code']));
    Logger::info('profile.user_updated', ['target_user_id' => $userId, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

/* ==========================================================================
   Organization profile / address / notifications (Invariant B/C/D)
   ========================================================================== */

function profile_organization_get(int $organizationId): array {
    $empty = [
        'organization_id' => $organizationId, 'account_type' => 'individual',
        'legal_name' => '', 'company_type' => 'unspecified',
        'registration_number' => '', 'national_id' => '', 'economic_code' => '', 'ceo_name' => '', 'ceo_last_name' => '',
        'ceo_father_name' => '', 'ceo_national_code' => '', 'ceo_birth_certificate_no' => '', 'ceo_birth_date' => null,
        'ceo_birth_city' => '', 'ceo_mobile' => '', 'ceo_email' => '',
        'landline_phone' => '', 'fax_number' => '', 'customer_code' => '',
        'company_start_date' => null, 'company_expiry_date' => null, 'legal_representative_user_id' => null,
    ];
    if ($organizationId <= 0) {
        return $empty;
    }
    try {
        $st = db()->prepare('SELECT * FROM ellsms_organization_profiles WHERE organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetch() ?: $empty;
    } catch (Throwable $t) {
        Logger::error('profile.organization_get_failed', ['organization_id' => $organizationId, 'exception' => $t]);
        return $empty;
    }
}

function profile_organization_save(int $organizationId, array $input, int $actorUserId): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }

    // §13 — "no silent data loss": a caller that supplies only SOME fields (the account_type toggle
    // on the profile page is exactly this — it deliberately sends nothing else) must never blank out
    // whatever is already on file. Any key ABSENT from $input falls back to the current row; a key
    // PRESENT with an empty string is still a real, explicit clear (a form field the user emptied on
    // purpose). The array union operator does exactly this: left operand wins per-key, right operand
    // only fills gaps — never overwrites a key $input already set, even to ''.
    $previous = profile_organization_get($organizationId);
    $input = $input + $previous;

    $companyType = in_array($input['company_type'] ?? '', array_keys(PROFILE_COMPANY_TYPES), true)
        ? $input['company_type'] : 'unspecified';

    // account_type is DURABLE and organization-scoped (§1 of the KYC phase brief) — changing it here
    // is a metadata switch only; §13's "no silent data loss" rule is enforced by never deleting the
    // dormant side's rows, never by refusing the switch itself.
    $accountType = in_array($input['account_type'] ?? '', array_keys(PROFILE_ACCOUNT_TYPES), true)
        ? $input['account_type'] : ($previous['account_type'] ?? 'individual');
    $accountTypeChanged = $accountType !== ($previous['account_type'] ?? 'individual');

    $ceoEmailRaw = profile_clean_text((string)($input['ceo_email'] ?? ''), 190);
    if ($ceoEmailRaw !== '' && !filter_var($ceoEmailRaw, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'invalid_ceo_email'];
    }

    $startDate  = $input['company_start_date'] ?? null;
    $expiryDate = $input['company_expiry_date'] ?? null;
    // A company that expires before it starts is not a data-entry preference, it is a mistake — and
    // it silently breaks any later "is this company still valid" question.
    if ($startDate !== null && $expiryDate !== null && $expiryDate < $startDate) {
        return ['ok' => false, 'reason' => 'expiry_before_start'];
    }

    $ceoNationalCodeRaw = (string)($input['ceo_national_code'] ?? '');
    $ceoNationalCode = profile_normalize_national_code($ceoNationalCodeRaw);
    if ($ceoNationalCodeRaw !== '' && $ceoNationalCode === '') {
        return ['ok' => false, 'reason' => 'invalid_ceo_national_code'];
    }

    // A legal representative, when linked, must actually be a member of THIS organization — otherwise
    // the field becomes a way to point at an arbitrary user id.
    $representativeId = (int)($input['legal_representative_user_id'] ?? 0);
    if ($representativeId > 0 && !can_access_organization($representativeId, $organizationId)) {
        return ['ok' => false, 'reason' => 'representative_not_a_member'];
    }

    db()->prepare(
        'INSERT INTO ellsms_organization_profiles
           (organization_id, account_type, legal_name, company_type, registration_number, national_id, economic_code,
            ceo_name, ceo_last_name, ceo_father_name, ceo_national_code, ceo_birth_certificate_no, ceo_birth_date,
            ceo_birth_city, ceo_mobile, ceo_email, landline_phone, fax_number, customer_code,
            company_start_date, company_expiry_date, legal_representative_user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           account_type = VALUES(account_type),
           legal_name = VALUES(legal_name), company_type = VALUES(company_type),
           registration_number = VALUES(registration_number), national_id = VALUES(national_id),
           economic_code = VALUES(economic_code), ceo_name = VALUES(ceo_name), ceo_last_name = VALUES(ceo_last_name),
           ceo_father_name = VALUES(ceo_father_name), ceo_national_code = VALUES(ceo_national_code),
           ceo_birth_certificate_no = VALUES(ceo_birth_certificate_no),
           ceo_birth_date = VALUES(ceo_birth_date), ceo_birth_city = VALUES(ceo_birth_city),
           ceo_mobile = VALUES(ceo_mobile), ceo_email = VALUES(ceo_email),
           landline_phone = VALUES(landline_phone), fax_number = VALUES(fax_number),
           customer_code = VALUES(customer_code),
           company_start_date = VALUES(company_start_date),
           company_expiry_date = VALUES(company_expiry_date),
           legal_representative_user_id = VALUES(legal_representative_user_id)'
    )->execute([
        $organizationId,
        $accountType,
        profile_clean_text((string)($input['legal_name'] ?? ''), 190),
        $companyType,
        profile_normalize_digits((string)($input['registration_number'] ?? '')),
        profile_normalize_digits((string)($input['national_id'] ?? '')),
        profile_normalize_digits((string)($input['economic_code'] ?? '')),
        profile_clean_text((string)($input['ceo_name'] ?? ''), 160),
        profile_clean_text((string)($input['ceo_last_name'] ?? ''), 120),
        profile_clean_text((string)($input['ceo_father_name'] ?? ''), 120),
        $ceoNationalCode,
        profile_normalize_digits((string)($input['ceo_birth_certificate_no'] ?? '')),
        $input['ceo_birth_date'] ?? null,
        profile_clean_text((string)($input['ceo_birth_city'] ?? ''), 60),
        profile_normalize_digits((string)($input['ceo_mobile'] ?? '')),
        $ceoEmailRaw,
        profile_normalize_digits((string)($input['landline_phone'] ?? '')),
        profile_normalize_digits((string)($input['fax_number'] ?? '')),
        profile_clean_text((string)($input['customer_code'] ?? ''), 40),
        $startDate,
        $expiryDate,
        $representativeId > 0 ? $representativeId : null,
    ]);

    if ($accountTypeChanged) {
        // A dedicated event (§19), separate from the generic profile.organization_update line, so an
        // auditor can find every account-type switch without grepping every field-level change.
        audit($actorUserId, 'profile.account_type_changed', "org={$organizationId} from={$previous['account_type']} to={$accountType}");
    }
    audit($actorUserId, 'profile.updated', "org={$organizationId} type={$companyType} account_type={$accountType}");
    Logger::info('profile.organization_updated', ['organization_id' => $organizationId, 'actor_user_id' => $actorUserId, 'account_type' => $accountType]);
    return ['ok' => true];
}

function profile_address_get(int $organizationId): array {
    $empty = [
        'organization_id' => $organizationId, 'country' => 'ایران', 'province' => '', 'city' => '',
        'district' => '', 'street' => '', 'alley' => '', 'building_no' => '', 'unit_no' => '',
        'postal_code' => '', 'address_text' => null,
    ];
    if ($organizationId <= 0) {
        return $empty;
    }
    try {
        $st = db()->prepare('SELECT * FROM ellsms_organization_addresses WHERE organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetch() ?: $empty;
    } catch (Throwable $t) {
        Logger::error('profile.address_get_failed', ['organization_id' => $organizationId, 'exception' => $t]);
        return $empty;
    }
}

function profile_address_save(int $organizationId, array $input, int $actorUserId): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }
    $postalRaw = (string)($input['postal_code'] ?? '');
    $postalCode = profile_normalize_postal_code($postalRaw);
    if ($postalRaw !== '' && $postalCode === '') {
        return ['ok' => false, 'reason' => 'invalid_postal_code'];
    }

    db()->prepare(
        'INSERT INTO ellsms_organization_addresses
           (organization_id, country, province, city, district, street, alley, building_no, unit_no, postal_code, address_text)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           country = VALUES(country), province = VALUES(province), city = VALUES(city),
           district = VALUES(district), street = VALUES(street), alley = VALUES(alley),
           building_no = VALUES(building_no), unit_no = VALUES(unit_no),
           postal_code = VALUES(postal_code), address_text = VALUES(address_text)'
    )->execute([
        $organizationId,
        profile_clean_text((string)($input['country'] ?? 'ایران'), 60) ?: 'ایران',
        profile_clean_text((string)($input['province'] ?? ''), 60),
        profile_clean_text((string)($input['city'] ?? ''), 60),
        profile_clean_text((string)($input['district'] ?? ''), 60),
        profile_clean_text((string)($input['street'] ?? ''), 190),
        profile_clean_text((string)($input['alley'] ?? ''), 120),
        profile_clean_text((string)($input['building_no'] ?? ''), 20),
        profile_clean_text((string)($input['unit_no'] ?? ''), 20),
        $postalCode,
        profile_clean_text((string)($input['address_text'] ?? ''), 500),
    ]);

    // The address itself is sensitive (STEP 38), so the audit records that it changed, not its value.
    audit($actorUserId, 'profile.address_update', "org={$organizationId}");
    Logger::info('profile.address_updated', ['organization_id' => $organizationId, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

function profile_notifications_get(int $organizationId): array {
    $empty = [
        'organization_id' => $organizationId, 'low_credit_alert_enabled' => 0, 'low_credit_threshold' => 0,
        'email_alert_enabled' => 0, 'sms_alert_enabled' => 0, 'alert_email' => '', 'alert_mobile' => '',
    ];
    if ($organizationId <= 0) {
        return $empty;
    }
    try {
        $st = db()->prepare('SELECT * FROM ellsms_organization_notification_preferences WHERE organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetch() ?: $empty;
    } catch (Throwable $t) {
        Logger::error('profile.notifications_get_failed', ['organization_id' => $organizationId, 'exception' => $t]);
        return $empty;
    }
}

function profile_notifications_save(int $organizationId, array $input, int $actorUserId): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }
    $threshold = (int)profile_normalize_digits((string)($input['low_credit_threshold'] ?? '0'));
    if ($threshold < 0) {
        return ['ok' => false, 'reason' => 'invalid_threshold'];
    }
    $alertMobile = profile_normalize_digits((string)($input['alert_mobile'] ?? ''));
    $alertEmail = profile_clean_text((string)($input['alert_email'] ?? ''), 190);
    if ($alertEmail !== '' && !filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'invalid_email'];
    }

    db()->prepare(
        'INSERT INTO ellsms_organization_notification_preferences
           (organization_id, low_credit_alert_enabled, low_credit_threshold, email_alert_enabled, sms_alert_enabled, alert_email, alert_mobile)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           low_credit_alert_enabled = VALUES(low_credit_alert_enabled),
           low_credit_threshold = VALUES(low_credit_threshold),
           email_alert_enabled = VALUES(email_alert_enabled),
           sms_alert_enabled = VALUES(sms_alert_enabled),
           alert_email = VALUES(alert_email), alert_mobile = VALUES(alert_mobile)'
    )->execute([
        $organizationId,
        !empty($input['low_credit_alert_enabled']) ? 1 : 0,
        $threshold,
        !empty($input['email_alert_enabled']) ? 1 : 0,
        !empty($input['sms_alert_enabled']) ? 1 : 0,
        $alertEmail,
        $alertMobile,
    ]);

    audit($actorUserId, 'profile.notifications_update', "org={$organizationId} threshold={$threshold}");
    Logger::info('profile.notifications_updated', ['organization_id' => $organizationId, 'actor_user_id' => $actorUserId, 'threshold' => $threshold]);
    return ['ok' => true];
}

/* ==========================================================================
   Documents (STEP 14–19, 29, 30)
   ========================================================================== */

/** Raised for any document validation failure; the message is safe to show (AppException semantics). */
class ProfileDocumentException extends AppException {}

/**
 * Validates and stores an uploaded document, archiving whatever active document of the same type
 * the same owner already had.
 *
 * $owner is ['user' => id] or ['organization' => id] — exactly one, matching the database's own
 * single-owner CHECK. Authorization is the CALLER's job (the pages call
 * profile_can_manage_* first); this function is the storage/validation layer, not the policy layer.
 */
function profile_document_store(array $owner, string $documentType, string $field, int $uploadedByUserId): array {
    [$ownerKind, $ownerId] = profile_owner_tuple($owner);

    $allowedTypes = profile_document_types_for($ownerKind);
    if (!array_key_exists($documentType, $allowedTypes)) {
        // A user document type filed against an organization (or the reverse) is refused outright —
        // that mix-up is how a company document ends up visible to the wrong audience.
        throw new ProfileDocumentException('نوع مدرک برای این بخش معتبر نیست.');
    }

    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new ProfileDocumentException('فایلی انتخاب نشده است.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ProfileDocumentException('بارگذاری فایل با خطا مواجه شد.');
    }
    // is_uploaded_file() is the guard that stops an arbitrary server path being passed off as an
    // upload; without it every check below could be run against a file the attacker chose.
    //
    // Skipped ONLY under the CLI SAPI, so that tests can exercise the storage path with a real file
    // on disk. This cannot weaken a web request: PHP_SAPI is never 'cli' for one, and nothing in
    // this application handles $_FILES from the command line — the workers and cron scripts have no
    // upload path at all. The real multipart flow is additionally covered end-to-end over HTTP in
    // tests/Integration/CustomerProfileHttpTest.php, so this seam is not the only coverage of it.
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'])) {
        throw new ProfileDocumentException('فایل معتبر نیست.');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > PROFILE_DOCUMENT_MAX_BYTES) {
        throw new ProfileDocumentException('حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
    }

    // REAL content inspection. The browser-reported type and the filename extension are both
    // attacker-controlled and neither is consulted for the decision.
    $mime = function_exists('mime_content_type') ? (mime_content_type($file['tmp_name']) ?: '') : '';
    if (!isset(PROFILE_DOCUMENT_ALLOWED_MIME[$mime])) {
        throw new ProfileDocumentException('فرمت فایل باید JPG، PNG، WEBP یا PDF باشد.');
    }

    $directory = profile_document_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new ProfileDocumentException('ذخیره‌ی فایل ممکن نشد.');
    }

    // The stored name is derived ENTIRELY from random bytes and the validated extension. Nothing the
    // uploader supplied reaches the filesystem, so path traversal and extension smuggling have no
    // surface here at all.
    $extension = PROFILE_DOCUMENT_ALLOWED_MIME[$mime];
    $storageKey = bin2hex(random_bytes(20)) . '.' . $extension;
    $destination = $directory . '/' . $storageKey;
    // Same reasoning as the is_uploaded_file() guard above: move_uploaded_file() refuses a file the
    // SAPI did not receive as an upload, so the CLI test path uses rename() on the same validated
    // temporary file. The web path is unchanged.
    $moved = PHP_SAPI === 'cli'
        ? rename($file['tmp_name'], $destination)
        : move_uploaded_file($file['tmp_name'], $destination);
    if (!$moved) {
        throw new ProfileDocumentException('ذخیره‌ی فایل ممکن نشد.');
    }
    @chmod($destination, 0640);

    $sha256 = hash_file('sha256', $destination) ?: '';
    $originalFilename = profile_clean_text((string)($file['name'] ?? ''), 255);

    try {
        $documentId = db_transaction(function (PDO $db) use ($ownerKind, $ownerId, $documentType, $storageKey, $originalFilename, $mime, $file, $sha256, $uploadedByUserId): int {
            // Archive first, inside the same transaction: the active slot is UNIQUE, so releasing it
            // and claiming it must be one atomic step or a concurrent upload could collide.
            profile_document_archive_active($db, $ownerKind, $ownerId, $documentType);

            $db->prepare(
                'INSERT INTO ellsms_profile_documents
                   (organization_id, user_id, document_type, storage_key, original_filename, mime_type,
                    size_bytes, sha256, status, uploaded_by_user_id, active_slot)
                 VALUES (?,?,?,?,?,?,?,?,\'active\',?,?)'
            )->execute([
                $ownerKind === 'organization' ? $ownerId : null,
                $ownerKind === 'user' ? $ownerId : null,
                $documentType, $storageKey, $originalFilename, $mime,
                (int)$file['size'], $sha256, $uploadedByUserId,
                profile_document_slot($ownerKind, $ownerId, $documentType),
            ]);
            return (int)$db->lastInsertId();
        });
    } catch (Throwable $t) {
        // Never leave a file on disk that no row points at.
        @unlink($destination);
        Logger::error('profile.document_store_failed', ['owner' => $ownerKind, 'owner_id' => $ownerId, 'exception' => $t]);
        throw new ProfileDocumentException('ثبت مدرک ممکن نشد.');
    }

    audit($uploadedByUserId, 'profile.document_upload', "{$ownerKind}=#{$ownerId} type={$documentType} id={$documentId}");
    Logger::info('profile.document_uploaded', [
        'owner_kind' => $ownerKind, 'owner_id' => $ownerId, 'document_type' => $documentType,
        'document_id' => $documentId, 'size_bytes' => (int)$file['size'],
    ]);
    return ['ok' => true, 'document_id' => $documentId];
}

/** 'u:<id>:<type>' / 'o:<id>:<type>' — the value the UNIQUE index uses to enforce one active document per type. */
function profile_document_slot(string $ownerKind, int $ownerId, string $documentType): string {
    return ($ownerKind === 'organization' ? 'o' : 'u') . ':' . $ownerId . ':' . $documentType;
}

/** Releases the active slot of whatever document currently holds it. The row and its FILE are kept. */
function profile_document_archive_active(PDO $db, string $ownerKind, int $ownerId, string $documentType): void {
    $db->prepare(
        "UPDATE ellsms_profile_documents
         SET status = 'archived', archived_at = UTC_TIMESTAMP(), active_slot = NULL
         WHERE active_slot = ?"
    )->execute([profile_document_slot($ownerKind, $ownerId, $documentType)]);
}

/** Archives one document by id, but only if it belongs to $owner — the id alone is never sufficient. */
function profile_document_archive(array $owner, int $documentId, int $actorUserId): array {
    [$ownerKind, $ownerId] = profile_owner_tuple($owner);
    $document = profile_document_find($documentId);
    if ($document === null || !profile_document_belongs_to($document, $ownerKind, $ownerId)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    db()->prepare("UPDATE ellsms_profile_documents SET status = 'archived', archived_at = UTC_TIMESTAMP(), active_slot = NULL WHERE id = ?")
        ->execute([$documentId]);

    audit($actorUserId, 'profile.document_archive', "{$ownerKind}=#{$ownerId} type={$document['document_type']} id={$documentId}");
    Logger::info('profile.document_archived', ['document_id' => $documentId, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

function profile_document_find(int $documentId): ?array {
    if ($documentId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM ellsms_profile_documents WHERE id = ?');
    $st->execute([$documentId]);
    return $st->fetch() ?: null;
}

/** Ownership comparison in ONE place, so no call site has to remember which column to compare. */
function profile_document_belongs_to(array $document, string $ownerKind, int $ownerId): bool {
    if ($ownerKind === 'organization') {
        return $document['organization_id'] !== null && (int)$document['organization_id'] === $ownerId;
    }
    return $document['user_id'] !== null && (int)$document['user_id'] === $ownerId;
}

/** @return list<array<string,mixed>> newest first; archived versions included so history is visible */
function profile_documents_list(array $owner, bool $includeArchived = true): array {
    [$ownerKind, $ownerId] = profile_owner_tuple($owner);
    $column = $ownerKind === 'organization' ? 'organization_id' : 'user_id';
    $sql = "SELECT * FROM ellsms_profile_documents WHERE {$column} = ?";
    if (!$includeArchived) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' ORDER BY status = \'active\' DESC, id DESC';
    try {
        $st = db()->prepare($sql);
        $st->execute([$ownerId]);
        return $st->fetchAll();
    } catch (Throwable $t) {
        Logger::error('profile.documents_list_failed', ['owner' => $ownerKind, 'owner_id' => $ownerId, 'exception' => $t]);
        return [];
    }
}

/** Normalizes the ['user'=>id] / ['organization'=>id] shape and refuses anything ambiguous. */
function profile_owner_tuple(array $owner): array {
    $hasUser = isset($owner['user']) && (int)$owner['user'] > 0;
    $hasOrganization = isset($owner['organization']) && (int)$owner['organization'] > 0;
    if ($hasUser === $hasOrganization) {
        // Both or neither: the one shape the database's CHECK constraint also refuses.
        throw new ProfileDocumentException('مالک مدرک نامعتبر است.');
    }
    return $hasOrganization ? ['organization', (int)$owner['organization']] : ['user', (int)$owner['user']];
}

/** Absolute path of a stored document. Validates the key's SHAPE before touching the filesystem. */
function profile_document_path(string $storageKey): ?string {
    if (preg_match('/^[0-9a-f]{40}\.(jpg|png|webp|pdf)$/', $storageKey) !== 1) {
        return null;
    }
    $path = profile_document_dir() . '/' . $storageKey;
    return is_file($path) ? $path : null;
}

/* ==========================================================================
   Authorization (STEP 21/22)
   ========================================================================== */

/**
 * Who may edit an organization's company profile, address, notification preferences and documents.
 *
 * Reuses `settings.manage` rather than minting new permissions (STEP 22's "smallest clean model"):
 * it is already the organization-configuration permission, already granted to owner and admin and
 * withheld from member, and already understood by anyone reading the role matrix. A new
 * profile.manage would have to be granted to exactly the same roles and would only add a second
 * thing to keep in sync.
 */
function profile_can_manage_organization(?array $membership): bool {
    return $membership !== null && membership_has_permission($membership, Permissions::SETTINGS_MANAGE);
}

/** Any active member may VIEW their organization's profile — it is the company they belong to. */
function profile_can_view_organization(?array $membership): bool {
    return $membership !== null;
}

/** Machine-readable save failure -> Persian message, shared by the self-service and admin pages. */
function profile_error_message(string $reason): string {
    return [
        'invalid_user'                => 'کاربر معتبر نیست.',
        'invalid_organization'        => 'سازمان فعالی برای این عملیات وجود ندارد.',
        'invalid_national_code'       => 'کد ملی باید دقیقاً ۱۰ رقم باشد.',
        'invalid_ceo_national_code'   => 'کد ملی مدیرعامل باید دقیقاً ۱۰ رقم باشد.',
        'invalid_ceo_email'           => 'ایمیل نماینده معتبر نیست.',
        'invalid_postal_code'         => 'کد پستی باید دقیقاً ۱۰ رقم باشد.',
        'invalid_threshold'           => 'آستانه‌ی اعتبار نامعتبر است.',
        'invalid_email'               => 'ایمیل اعلان معتبر نیست.',
        'expiry_before_start'         => 'تاریخ انقضا نمی‌تواند پیش از تاریخ شروع فعالیت باشد.',
        'representative_not_a_member' => 'نماینده‌ی قانونی باید عضو همین سازمان باشد.',
    ][$reason] ?? 'ذخیره‌سازی ممکن نشد.';
}

/* ==========================================================================
   Completeness (STEP 31) — informational only, never a gate
   ========================================================================== */

/** @return array{percent:int, missing:list<string>} */
function profile_user_completeness(array $profile): array {
    $fields = [
        'father_name' => 'نام پدر', 'national_code' => 'کد ملی',
        'birth_certificate_no' => 'شماره شناسنامه', 'birth_date' => 'تاریخ تولد',
    ];
    $missing = [];
    foreach ($fields as $key => $label) {
        if (($profile[$key] ?? '') === '' || $profile[$key] === null) {
            $missing[] = $label;
        }
    }
    $total = count($fields);
    return ['percent' => (int)round((($total - count($missing)) / $total) * 100), 'missing' => $missing];
}

/**
 * Company completeness. Which fields COUNT depends on company_type: an individual business has no
 * registration number, so scoring it against one would permanently report an unfixable gap.
 *
 * @return array{percent:int, missing:list<string>}
 */
function profile_organization_completeness(array $profile, array $address): array {
    $labels = [
        'legal_name' => 'نام حقوقی', 'registration_number' => 'شماره ثبت', 'national_id' => 'شناسه ملی',
        'economic_code' => 'کد اقتصادی', 'ceo_name' => 'مدیرعامل',
    ];
    $required = ($profile['company_type'] ?? 'unspecified') === 'legal_entity'
        ? PROFILE_LEGAL_ENTITY_REQUIRED_FIELDS
        : ['legal_name', 'ceo_name'];

    $missing = [];
    foreach ($required as $field) {
        if (($profile[$field] ?? '') === '') {
            $missing[] = $labels[$field] ?? $field;
        }
    }
    if (($address['postal_code'] ?? '') === '') {
        $missing[] = 'کد پستی';
    }
    if (($address['city'] ?? '') === '') {
        $missing[] = 'شهر';
    }
    $total = count($required) + 2;
    return ['percent' => (int)round((($total - count($missing)) / $total) * 100), 'missing' => $missing];
}

/**
 * The single, centralized profile-completion figure shown at the top of the profile page (§14 of the
 * KYC phase brief) — "تکمیل پروفایل: NN٪". Deterministic and testable: which underlying score it
 * reports depends only on account_type, never on which fields happen to be filled in.
 *
 * individual -> personal identity fields (profile_user_completeness) plus the organization's address,
 * since §3's individual field set explicitly includes address/contact information.
 * legal -> the existing company completeness score, which already folds the address in.
 *
 * Optional fields (landline, fax, customer_code, alley/plaque beyond what's already scored) are
 * deliberately NOT counted — §3/§4's "do not unnecessarily require optional fields."
 *
 * @return array{percent:int, missing:list<string>}
 */
function profile_account_completeness(string $accountType, array $userProfile, array $organizationProfile, array $address): array {
    if ($accountType === 'legal') {
        return profile_organization_completeness($organizationProfile, $address);
    }

    $personal = profile_user_completeness($userProfile);
    $addressLabels = ['postal_code' => 'کد پستی', 'city' => 'شهر'];
    $missing = $personal['missing'];
    foreach ($addressLabels as $field => $label) {
        if (($address[$field] ?? '') === '') {
            $missing[] = $label;
        }
    }
    $total = 4 /* profile_user_completeness's own field count */ + count($addressLabels);
    return ['percent' => (int)round((($total - count($missing)) / $total) * 100), 'missing' => $missing];
}
