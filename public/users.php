<?php
require_once __DIR__ . '/../app/backend.php'; // needed for backend_create_account()
$me = require_admin();
$pageTitle = 'کاربران';
$active = 'users';

/*
 * حساب‌سازی واقعی: از اندپوینت خودِ سامانه‌ی مرکزی (POST /api/users/)
 * استفاده می‌شود، نه نوشتن مستقیم در جدول user_ — چون آن اندپوینت همان
 * منطق هش رمز عبور و مقداردهی پیش‌فرض‌ها را دارد که بقیه‌ی سامانه انتظارش
 * را دارد. هر دامنه (Domain) باید از قبل در سامانه‌ی مرکزی ساخته شده
 * باشد؛ ELLSMS دامنه نمی‌سازد، فقط از میان دامنه‌های موجود انتخاب می‌کند.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'grant') {
        // Phase 8 (Invariant B): identity provider, not a direct user_ query.
        $targetUserId = backend_find_user_id_by_username(trim($_POST['username'] ?? ''));
        if (!$targetUserId) {
            flash('error', 'حسابی با این نام کاربری پیدا نشد.');
        } else {
            db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                           VALUES (?,1,0,?)
                           ON DUPLICATE KEY UPDATE panel_access=1')
               ->execute([$targetUserId, setting('default_originator', '')]);
            audit((int)$me['id'], 'user.grant_access', (string)$targetUserId);
            Logger::info('user.grant_access', ['actor_id' => $me['id'], 'target_id' => $targetUserId]);
            // Every ELLSMS-managed account needs a valid organization context (docs/profile-kyc.md)
            // — an account brought in through "grant" (as opposed to "create_account", which makes a
            // brand-new backend user) may be years old and still never have one. Zero-cost when it
            // already does: ensure_user_has_organization() only acts on the genuinely empty case.
            $identity = backend_users_by_ids([$targetUserId])[$targetUserId] ?? null;
            $displayName = $identity ? (trim($identity['first_name'] . ' ' . $identity['last_name']) ?: $identity['username']) : ('user#' . $targetUserId);
            $orgResult = ensure_user_has_organization($targetUserId, $displayName . "'s Workspace");
            if ($orgResult['created']) {
                audit((int)$me['id'], 'user.organization_ensured', "#{$targetUserId} org=#{$orgResult['organization_id']}");
            }
            flash('success', 'دسترسی به ELLSMS داده شد.');
        }
    } elseif ($do === 'enable_2fa_all') {
        $n = db()->exec('UPDATE ellsms_meta SET twofa_enabled = 1 WHERE panel_access = 1');
        audit((int)$me['id'], 'user.enable_2fa_all', (string)$n);
        flash('success', 'ورود دومرحله‌ای برای همه‌ی کاربران فعال شد.');
    } elseif (in_array($do, ['revoke', 'toggle_admin', 'toggle_2fa', 'originator', 'credit', 'password', 'kyc_save', 'ensure_organization', 'account_type'], true)) {
        /*
         * Every action in this branch targets an EXISTING ELLSMS-managed
         * account only — an ELLSMS admin is not automatically a global
         * administrator of the backend platform
         * (docs/security-review.md CRITICAL finding #2).
         * resolve_ellsms_managed_user() (app/authorization.php) is the
         * one gate all of them go through now instead of each having its
         * own subtly different (or missing) check; "grant" and
         * "create_account" are the sole, deliberate exceptions, since
         * their entire purpose is bringing a not-yet-managed account in.
         */
        $target = resolve_ellsms_managed_user($id);
        if (!$target) {
            flash('error', 'این حساب در محدوده‌ی مدیریت ELLSMS نیست یا یافت نشد.');
        } elseif (in_array($do, ['revoke', 'toggle_admin'], true) && !can_demote_or_revoke($me, $id)) {
            flash('error', 'شما نمی‌توانید این عملیات را روی حساب خودتان انجام دهید.');
        } elseif ($do === 'revoke') {
            db()->prepare('UPDATE ellsms_meta SET panel_access=0, is_admin=0 WHERE user_id=?')->execute([$id]);
            audit((int)$me['id'], 'user.revoke_access', "#{$id}");
            Logger::info('user.revoke_access', ['actor_id' => $me['id'], 'target_id' => $id]);
            flash('info', 'دسترسی به ELLSMS لغو شد.');
        } elseif ($do === 'toggle_admin') {
            db()->prepare('UPDATE ellsms_meta SET is_admin = 1 - is_admin WHERE user_id=?')->execute([$id]);
            audit((int)$me['id'], 'user.toggle_admin', "#{$id}");
            Logger::info('user.toggle_admin', ['actor_id' => $me['id'], 'target_id' => $id]);
            flash('info', 'نقش مدیر تغییر کرد.');
        } elseif ($do === 'toggle_2fa') {
            db()->prepare('UPDATE ellsms_meta SET twofa_enabled = 1 - twofa_enabled WHERE user_id=?')->execute([$id]);
            audit((int)$me['id'], 'user.toggle_2fa', "#{$id}");
            flash('info', 'وضعیت ورود دومرحله‌ای تغییر کرد.');
        } elseif ($do === 'originator') {
            $newOriginator = normalize_originator($_POST['originator'] ?? '') ?? '';
            db()->prepare('UPDATE ellsms_meta SET originator=? WHERE user_id=?')
               ->execute([$newOriginator, $id]);
            audit((int)$me['id'], 'user.originator_update', "#{$id}");
            flash('success', 'خط ارسال به‌روزرسانی شد.');
        } elseif ($do === 'credit') {
            // Phase 3 (STEP 17): goes through the wallet ledger instead of
            // a direct UPDATE currentcredit — every manual adjustment is
            // now an auditable, idempotent ellsms_wallet_transactions row
            // (type manual_credit/manual_debit), not just an audit-log
            // line with no durable link to a specific balance change. A
            // debit larger than the account's balance still clamps at
            // zero (wallet_manual_adjustment()'s own docblock), matching
            // the pre-Phase-3 GREATEST(0, ...) behavior exactly.
            $amount = (int)($_POST['amount'] ?? 0);
            $reason = trim($_POST['reason'] ?? '') ?: 'تنظیم دستی اعتبار توسط مدیر';
            // A 10-second dedup window on the exact (target, amount,
            // reason) tuple — enough to absorb an accidental double-
            // submit (double-click, browser back-and-resubmit) without
            // blocking a deliberate identical adjustment made later.
            $idempotencyKey = 'user_credit:' . $id . ':' . $amount . ':' . sha1($reason) . ':' . (int)floor(time() / 10);
            $adj = wallet_manual_adjustment($id, $amount, (int)$me['id'], $reason, $idempotencyKey);
            audit((int)$me['id'], 'user.credit', "#{$id} " . ($amount >= 0 ? '+' : '') . $amount . ' — ' . $reason);
            Logger::info('user.credit_adjusted', ['actor_id' => $me['id'], 'target_id' => $id, 'amount' => $amount, 'reason' => $reason]);
            flash('success', 'اعتبار به میزان ' . to_persian_digits(number_format($amount)) . ' تغییر کرد.');
        } elseif ($do === 'password') {
            $p = $_POST['password'] ?? '';
            if (strlen($p) < 6) {
                flash('error', 'رمز عبور باید حداقل ۶ نویسه باشد.');
            } else {
                backend_update_user_password($id, backend_hash_password($p));
                audit((int)$me['id'], 'user.password_reset', "#{$id}");
                Logger::info('user.password_reset', ['actor_id' => $me['id'], 'target_id' => $id]);
                flash('success', 'رمز عبور تغییر کرد. توجه: این رمز همه‌جا برای این حساب استفاده می‌شود، نه فقط در ELLSMS.');
            }
        } elseif ($do === 'kyc_save') {
            try {
                $idCardFile = kyc_store_upload('id_card_photo', $id);
                $secondFile = kyc_store_upload('second_doc_photo', $id);
                $fatherName = trim($_POST['father_name'] ?? '');
                $address    = trim($_POST['address'] ?? '');

                db()->prepare('INSERT IGNORE INTO ellsms_user_kyc (user_id) VALUES (?)')->execute([$id]);

                $sets = ['father_name = ?', 'address = ?'];
                $params = [$fatherName, $address];
                if ($idCardFile) { $sets[] = 'id_card_photo = ?'; $params[] = $idCardFile; }
                if ($secondFile) { $sets[] = 'second_doc_photo = ?'; $params[] = $secondFile; }
                $params[] = $id;

                db()->prepare('UPDATE ellsms_user_kyc SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
                   ->execute($params);

                audit((int)$me['id'], 'user.kyc_save', "#{$id}");
                flash('success', 'اطلاعات هویتی ذخیره شد.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        } elseif ($do === 'ensure_organization') {
            // Admin repair path for requirement §6 — safe ONLY because ensure_user_has_organization()
            // itself refuses to act on anyone who already has a membership (ambiguous or not); this
            // button can never reassign a user to an existing/arbitrary organization.
            $displayName = trim($target['first_name'] . ' ' . $target['last_name']) ?: $target['username'];
            $orgResult = ensure_user_has_organization($id, $displayName . "'s Workspace");
            if ($orgResult['ok'] && $orgResult['created']) {
                audit((int)$me['id'], 'user.organization_ensured', "#{$id} org=#{$orgResult['organization_id']}");
                flash('success', 'سازمان پیش‌فرض برای این کاربر ساخته شد.');
            } elseif ($orgResult['ok'] && !$orgResult['created']) {
                flash('info', 'این کاربر از قبل سازمان دارد؛ تغییری اعمال نشد.');
            } else {
                flash('error', 'ساخت سازمان پیش‌فرض ممکن نشد. دوباره تلاش کنید.');
            }
        } elseif ($do === 'account_type') {
            // Mirrors public/profile.php's own standalone account_type action exactly: resolve the
            // CURRENT full profile row and overlay only account_type, so profile_organization_save()'s
            // merge-safe contract can never blank any other field on this organization (§13 — no
            // silent data loss).
            $targetOrganizationId = (int)($_POST['organization_id'] ?? 0);
            $targetOrganizationId = $targetOrganizationId > 0 && can_access_organization($id, $targetOrganizationId)
                ? $targetOrganizationId
                : (user_primary_organization_id_for_display($id) ?? 0);
            if ($targetOrganizationId <= 0) {
                flash('error', 'این کاربر عضو هیچ سازمانی نیست — ابتدا سازمان پیش‌فرض را بسازید.');
            } else {
                $current = profile_organization_get($targetOrganizationId);
                $current['account_type'] = $_POST['account_type'] ?? $current['account_type'];
                $result = profile_organization_save($targetOrganizationId, $current, (int)$me['id']);
                flash($result['ok'] ? 'success' : 'error', $result['ok']
                    ? 'نوع حساب به‌روزرسانی شد.'
                    : profile_error_message((string)$result['reason']));
            }
        }
    }

    if ($do === 'create_account') {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $mobile     = normalize_msisdn($_POST['mobile'] ?? '');
        $nationalId = trim(from_persian_digits($_POST['national_id'] ?? ''));
        $domainId   = (int)($_POST['domain_id'] ?? 0);
        $gender     = ($_POST['gender'] ?? 'MALE') === 'FEMALE' ? 'FEMALE' : 'MALE';
        $dailyLimit = max(1, (int)($_POST['daily_limit'] ?? 1000));
        // نوع حساب — defaults safely to 'individual' for anything missing/unrecognized, matching
        // db/migrations/2026_08_17_kyc_workflow.sql's own backfill default; never trusts a raw string.
        $accountType = in_array($_POST['account_type'] ?? '', array_keys(PROFILE_ACCOUNT_TYPES), true)
            ? $_POST['account_type'] : 'individual';

        if ($username === '' || strlen($password) < 6 || $firstName === '' || $lastName === ''
            || $email === '' || !$mobile || strlen($nationalId) !== 10 || !$domainId) {
            flash('error', 'همه‌ی فیلدهای ستاره‌دار را به‌درستی پر کنید (کد ملی باید دقیقاً ۱۰ رقم باشد).');
        } else {
            [$ok, $info, $created] = backend_create_account([
                'username'         => $username,
                'password'         => $password,
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'email'            => $email,
                'mobile'           => (int)$mobile,
                'national_id'      => $nationalId,
                'domain_id'        => $domainId,
                'gender'           => $gender,
                'code'             => (string)random_int(10000000, 99999999),
                'daily_limit'      => $dailyLimit,
                'min_credit_notify'=> 0,
                'limit_time_from'  => '00:00',
                'limit_time_to'    => '23:59',
            ]);

            if ($ok && $created && !empty($created['id'])) {
                $newUserId = (int)$created['id'];
                // Everything ELLSMS itself owns for this brand-new user — panel access, its default
                // organization, and that organization's account_type — is written as one local
                // transaction (backend_create_account() above already made its own, separate,
                // external call to the shared backend and cannot be folded into this one; see
                // app/backend.php's own docblock on that boundary). ensure_user_has_organization() is
                // lock-protected and idempotent on its own, so a retried/duplicated POST here still
                // never creates a second organization for the same user_id.
                db_transaction(function () use ($newUserId, $username, $firstName, $lastName, $accountType, $me): void {
                    db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                                   VALUES (?,1,0,?)
                                   ON DUPLICATE KEY UPDATE panel_access=1')
                       ->execute([$newUserId, setting('default_originator', '')]);

                    $displayName = trim($firstName . ' ' . $lastName) ?: $username;
                    $orgResult = ensure_user_has_organization($newUserId, $displayName . "'s Workspace");
                    if ($orgResult['ok'] && $orgResult['organization_id']) {
                        profile_organization_save($orgResult['organization_id'], ['account_type' => $accountType], (int)$me['id']);
                    }
                });
                audit((int)$me['id'], 'user.create_account', $username);
                flash('success', 'حساب «' . $username . '» ساخته شد و دسترسی ELLSMS نیز فعال شد.');
                redirect('/users.php?edit=' . $newUserId);
            } else {
                flash('error', 'ساخت حساب ناموفق بود: ' . $info);
            }
        }
    }

    // Customer/organization profile (docs/customer-profile.md). Kept in its own branch rather than
    // folded into the list above because these actions are scoped differently: the personal profile
    // targets the USER being edited, while company/address/alerts target that user's ORGANIZATION.
    if (in_array($do, ['profile_personal', 'profile_organization', 'profile_address', 'profile_notifications', 'profile_document_upload', 'profile_document_archive'], true)) {
        $target = resolve_ellsms_managed_user($id);
        if (!$target) {
            flash('error', 'این حساب در محدوده‌ی مدیریت ELLSMS نیست یا یافت نشد.');
        } else {
            // The organization is resolved from the TARGET's own memberships, never from the request:
            // accepting an organization_id here would let a crafted POST write another tenant's
            // company profile through the admin page.
            $targetOrganizationId = (int)($_POST['organization_id'] ?? 0);
            $targetOrganizationId = $targetOrganizationId > 0 && can_access_organization($id, $targetOrganizationId)
                ? $targetOrganizationId
                : (int)(user_default_organization_id($id) ?? 0);

            if ($do === 'profile_personal') {
                $result = profile_user_save($id, [
                    'father_name'          => $_POST['father_name'] ?? '',
                    'national_code'        => $_POST['national_code'] ?? '',
                    'birth_certificate_no' => $_POST['birth_certificate_no'] ?? '',
                    'birth_date'           => profile_date_from_request('birth_date'),
                    'gender'               => $_POST['gender'] ?? 'unspecified',
                    'personal_address'     => $_POST['personal_address'] ?? '',
                ], (int)$me['id']);
                flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'اطلاعات فردی ذخیره شد.' : profile_error_message((string)$result['reason']));
            } elseif ($targetOrganizationId <= 0) {
                flash('error', 'این کاربر عضو هیچ سازمانی نیست، بنابراین اطلاعات سازمانی قابل ثبت نیست.');
            } elseif ($do === 'profile_organization') {
                $result = profile_organization_save($targetOrganizationId, [
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
                flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'اطلاعات سازمان ذخیره شد.' : profile_error_message((string)$result['reason']));
            } elseif ($do === 'profile_address') {
                $result = profile_address_save($targetOrganizationId, $_POST, (int)$me['id']);
                flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'آدرس ذخیره شد.' : profile_error_message((string)$result['reason']));
            } elseif ($do === 'profile_notifications') {
                $result = profile_notifications_save($targetOrganizationId, $_POST, (int)$me['id']);
                flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تنظیمات اعلان ذخیره شد.' : profile_error_message((string)$result['reason']));
            } elseif ($do === 'profile_document_upload') {
                $owner = ($_POST['owner'] ?? 'user') === 'organization' ? ['organization' => $targetOrganizationId] : ['user' => $id];
                try {
                    profile_document_store($owner, (string)($_POST['document_type'] ?? ''), 'document', (int)$me['id']);
                    flash('success', 'مدرک بارگذاری شد.');
                } catch (RuntimeException $e) {
                    flash('error', $e->getMessage());
                }
            } elseif ($do === 'profile_document_archive') {
                $owner = ($_POST['owner'] ?? 'user') === 'organization' ? ['organization' => $targetOrganizationId] : ['user' => $id];
                $result = profile_document_archive($owner, (int)($_POST['document_id'] ?? 0), (int)$me['id']);
                flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'مدرک بایگانی شد.' : 'مدرک یافت نشد.');
            }
        }
        redirect('/users.php?edit=' . $id);
    }

    redirect('/users.php' . (!empty($_POST['back']) ? '?edit=' . $id : ''));
}

$editUser = null;
$editKyc  = null;
if (!empty($_GET['edit'])) {
    // resolve_ellsms_managed_user() (app/authorization.php) — same gate
    // every mutating action above uses. Previously this queried user_
    // directly with no panel_access filter, so any id in the shared
    // backend database could be loaded here regardless of whether it
    // was ever granted ELLSMS access (docs/security-review.md CRITICAL
    // finding #2).
    $editUser = resolve_ellsms_managed_user((int)$_GET['edit']);
    if (!$editUser) {
        flash('error', 'این حساب در محدوده‌ی مدیریت ELLSMS نیست یا یافت نشد.');
    } else {
        $kst = db()->prepare('SELECT * FROM ellsms_user_kyc WHERE user_id = ?');
        $kst->execute([$editUser['id']]);
        $editKyc = $kst->fetch() ?: ['father_name' => '', 'address' => '', 'id_card_photo' => null, 'second_doc_photo' => null];

        // Customer/organization profile. The organization comes from the TARGET's memberships —
        // this page never accepts one from the request (docs/customer-profile.md).
        $editUserId = (int)$editUser['id'];
        $editProfile = profile_user_get($editUserId);
        $editUserDocuments = profile_documents_list(['user' => $editUserId]);
        $editMemberships = user_organization_memberships($editUserId);
        // user_default_organization_id() deliberately returns null for anything but EXACTLY one
        // membership (it feeds behavioral resolution elsewhere, e.g. legal-representative linking,
        // where guessing among several would be wrong). This admin screen instead falls back to
        // user_primary_organization_id_for_display() for a multi-organization user — display only,
        // never written anywhere without the admin explicitly choosing that organization_id — so the
        // page shows real data instead of silently omitting every organization-scoped card. Root
        // cause of the originally-reported bug: a user with ZERO memberships (created via the
        // create_account/grant flows before they called ensure_user_has_organization()) resolved to
        // organization_id 0 here exactly like this, and every card below was skipped without
        // explanation — see the "no organization" branch further down for that case specifically.
        $editOrganizationId = (int)(user_default_organization_id($editUserId) ?? user_primary_organization_id_for_display($editUserId) ?? 0);
        $editOrganization = $editOrganizationId > 0 ? organization_membership($editUserId, $editOrganizationId) : null;
        $editOrgProfile = $editOrganizationId > 0 ? profile_organization_get($editOrganizationId) : null;
        $editAddress = $editOrganizationId > 0 ? profile_address_get($editOrganizationId) : null;
        $editNotifications = $editOrganizationId > 0 ? profile_notifications_get($editOrganizationId) : null;
        $editOrgDocuments = $editOrganizationId > 0 ? profile_documents_list(['organization' => $editOrganizationId]) : [];
    }
}

// Phase 8 (Invariant A/B): the ELLSMS-owned half (ellsms_meta) stays a direct query here; the
// backend-owned half (user_ identity fields, outbound_message counts) is fetched in bulk through
// the identity/message repositories and merged in PHP, instead of one query joining across the
// ownership boundary.
$metaRows = db()->query(
    "SELECT m.user_id, m.panel_access, m.is_admin, m.originator, m.twofa_enabled
     FROM ellsms_meta m WHERE m.panel_access = 1"
)->fetchAll();
$userIds = array_column($metaRows, 'user_id');
$identities = backend_users_by_ids($userIds);
$sentCounts = backend_outbound_sent_counts_for_users($userIds);

// نوع حساب per row — TWO bulk queries total for the whole list, never one per user (STEP: no N+1).
// A user with more than one organization shows the account_type of their oldest membership (display
// only, same deterministic rule as user_primary_organization_id_for_display()); a user with zero
// memberships shows as "بدون سازمان" — itself a useful signal, not an error swallowed silently.
$accountTypeByUser = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $orgByUser = [];
    $membershipRows = db()->prepare(
        "SELECT user_id, organization_id FROM ellsms_organization_memberships
         WHERE status = 'active' AND user_id IN ({$placeholders}) ORDER BY id"
    );
    $membershipRows->execute($userIds);
    foreach ($membershipRows->fetchAll() as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($orgByUser[$uid])) { // first (oldest) membership only, per user
            $orgByUser[$uid] = (int)$row['organization_id'];
        }
    }
    if ($orgByUser) {
        $orgIds = array_values(array_unique($orgByUser));
        $orgPlaceholders = implode(',', array_fill(0, count($orgIds), '?'));
        $profileRows = db()->prepare(
            "SELECT organization_id, account_type FROM ellsms_organization_profiles WHERE organization_id IN ({$orgPlaceholders})"
        );
        $profileRows->execute($orgIds);
        $typeByOrg = [];
        foreach ($profileRows->fetchAll() as $row) {
            $typeByOrg[(int)$row['organization_id']] = (string)$row['account_type'];
        }
        foreach ($orgByUser as $uid => $organizationId) {
            // No ellsms_organization_profiles row yet is exactly PROFILE_ACCOUNT_TYPES's own default
            // ('individual') — the same fallback profile_organization_get() already applies.
            $accountTypeByUser[$uid] = $typeByOrg[$organizationId] ?? 'individual';
        }
    }
}

$panelUsers = [];
foreach ($metaRows as $meta) {
    $identity = $identities[(int)$meta['user_id']] ?? null;
    if (!$identity) {
        continue; // an ellsms_meta row whose backend account vanished — not this listing's concern to guess at
    }
    $panelUsers[] = array_merge($identity, $meta, [
        'sent_count' => $sentCounts[(int)$meta['user_id']] ?? 0,
        'account_type' => $accountTypeByUser[(int)$meta['user_id']] ?? null,
    ]);
}
usort($panelUsers, static fn($a, $b) => ((int)$b['is_admin'] <=> (int)$a['is_admin']) ?: ($a['username'] <=> $b['username']));

$domains = backend_list_domains();

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($editUser): ?>
<div class="card">
  <h2><?= e($editUser['username']) ?> <a class="btn btn-sm btn-ghost" style="float:left" href="/users.php">← بازگشت به فهرست</a></h2>
  <?php if (!$editUser['active'] || $editUser['deleted']): ?>
    <div class="flash flash-error">این حساب غیرفعال یا حذف‌شده است — تا زمانی که در سامانه‌ی مرکزی اصلاح نشود، امکان ورود ندارد.</div>
  <?php endif; ?>
  <div class="grid grid-2">
    <div>
      <table>
        <tr><th>نام</th><td><?= e(trim($editUser['first_name'] . ' ' . $editUser['last_name'])) ?></td></tr>
        <tr><th>موبایل</th><td class="msisdn"><?= e((string)$editUser['mobile']) ?></td></tr>
        <tr><th>نقش</th><td><span class="badge badge-<?= $editUser['is_admin'] ? 'admin' : 'user' ?>"><?= $editUser['is_admin'] ? 'مدیر' : 'کاربر' ?></span></td></tr>
        <tr><th>اعتبار</th><td class="num"><?= to_persian_digits(number_format((float)$editUser['credit'])) ?></td></tr>
        <tr><th>ورود دومرحله‌ای</th><td><span class="badge badge-<?= $editUser['twofa_enabled'] ? 'ok' : 'off' ?>"><?= $editUser['twofa_enabled'] ? 'فعال' : 'غیرفعال' ?></span></td></tr>
      </table>
      <div class="toolbar" style="margin-top:10px">
        <?php if ((int)$editUser['id'] !== (int)$me['id']): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_admin">
          <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
          <button class="btn btn-sm"><?= $editUser['is_admin'] ? 'حذف نقش مدیر' : 'تبدیل به مدیر' ?></button>
        </form>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_2fa">
          <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
          <button class="btn btn-sm"><?= $editUser['twofa_enabled'] ? 'غیرفعال‌سازی ۲مرحله‌ای' : 'فعال‌سازی ۲مرحله‌ای' ?></button>
        </form>
        <?php
        /*
         * Support impersonation (docs/admin-impersonation.md). A LINK to a confirmation page, not a
         * one-click POST: entering a customer's account is exactly the kind of action that should
         * cost a deliberate second step, and the confirmation page is where the reason is captured.
         * Shown only when the target is actually impersonable, so the operator does not click into a
         * refusal.
         */
        ?>
        <?php if (impersonation_target_refusal($editUser, (int)$me['id']) === null): ?>
          <a class="btn btn-sm btn-primary" href="/impersonate.php?target=<?= (int)$editUser['id'] ?>">ورود به پنل مشتری</a>
        <?php endif; ?>
      </div>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="originator">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <label>خط ارسال پیش‌فرض (قدیمی) <input type="text" name="originator" value="<?= e((string)$editUser['originator']) ?>" class="ltr"></label>
      <div class="hint">اگر از صفحه‌ی «شماره‌ها» شماره‌ای به این کاربر تخصیص داده شده باشد، در ارسال پیامک به‌جای این مقدار از آن استفاده می‌شود.</div>
      <button class="btn btn-primary btn-sm">ذخیره</button>
    </form>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>تعیین رمز عبور جدید</h2>
    <p class="hint">این کار رمز عبور حساب را همه‌جا تغییر می‌دهد، نه فقط در ELLSMS.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>رمز عبور جدید <input type="password" name="password" minlength="6" required></label>
      <button class="btn btn-primary">تغییر رمز عبور</button>
    </form>
  </div>
  <div class="card">
    <h2>اعتبار — فعلی: <span class="num"><?= to_persian_digits(number_format((float)$editUser['credit'])) ?></span></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>افزایش / کاهش اعتبار <input type="number" name="amount" value="1000" required>
        <div class="hint">برای کاهش، عدد منفی وارد کنید. اعتبار = بخش‌های پیامک.</div>
      </label>
      <label>دلیل (اختیاری) <input type="text" name="reason" maxlength="190" placeholder="مثلاً: جبران خطای ارسال">
        <div class="hint">برای پیگیری در تاریخچه‌ی مالی ثبت می‌شود.</div>
      </label>
      <button class="btn btn-primary">اعمال</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>اطلاعات فردی مشتری</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_personal">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <div class="grid grid-2">
      <label>نام پدر <input type="text" name="father_name" value="<?= e((string)$editProfile['father_name']) ?>" maxlength="120"></label>
      <label>کد ملی <input type="text" name="national_code" class="ltr" value="<?= e((string)$editProfile['national_code']) ?>" maxlength="20"></label>
      <label>شماره شناسنامه <input type="text" name="birth_certificate_no" class="ltr" value="<?= e((string)$editProfile['birth_certificate_no']) ?>" maxlength="30"></label>
      <label>جنسیت
        <select name="gender">
          <?php foreach (PROFILE_GENDERS as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($editProfile['gender'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>تاریخ تولد <?= jalali_date_select('birth_date', $editProfile['birth_date'] ?? null) ?></label>
      <label>آدرس شخصی <input type="text" name="personal_address" value="<?= e((string)($editProfile['personal_address'] ?? '')) ?>" maxlength="500"></label>
    </div>
    <button class="btn btn-primary btn-sm">ذخیره‌ی اطلاعات فردی</button>
  </form>
</div>

<?php if ($editOrganizationId === 0): ?>
<div class="card">
  <h2>سازمان</h2>
  <div class="flash flash-error">
    این کاربر به هیچ سازمانی متصل نیست، بنابراین نوع حساب (حقیقی/حقوقی)، اطلاعات شرکت، آدرس و مدارک سازمانی قابل نمایش یا ثبت نیستند.
    این وضعیت معمولاً برای حساب‌های قدیمی‌تر از فعال‌سازی خودکار سازمان رخ می‌دهد و با یک کلیک قابل رفع است.
  </div>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="ensure_organization">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="back" value="1">
    <button class="btn btn-primary btn-sm">ساخت سازمان پیش‌فرض برای این کاربر</button>
  </form>
  <p class="hint">این عملیات فقط وقتی کاربر عضو هیچ سازمانی نباشد اثر می‌کند؛ هرگز کاربر را به سازمان دیگری متصل نمی‌کند.</p>
</div>
<?php else: ?>
<div class="card">
  <h2>نوع حساب</h2>
  <?php if (count($editMemberships) > 1): ?>
    <p class="hint">این کاربر عضو <?= to_persian_digits((string)count($editMemberships)) ?> سازمان است؛ نوع حساب زیر مربوط به سازمان «<?= e((string)($editOrganization['name'] ?? '')) ?>» (سازمان نمایش‌داده‌شده) است.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="account_type">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
    <input type="hidden" name="back" value="1">
    <div class="segmented" role="radiogroup" aria-label="نوع حساب">
      <?php foreach (PROFILE_ACCOUNT_TYPES as $value => $label): ?>
        <input type="radio" id="edit_account_type_<?= e($value) ?>" name="account_type" value="<?= e($value) ?>"<?= ($editOrgProfile['account_type'] ?? 'individual') === $value ? ' checked' : '' ?>>
        <label for="edit_account_type_<?= e($value) ?>"><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-sm" style="margin-top:10px">اعمال نوع حساب</button>
  </form>
  <p class="hint">تغییر نوع حساب اطلاعات و مدارک بخش دیگر را حذف نمی‌کند؛ فقط بخش نمایش‌داده‌شده در پروفایل مشتری را تغییر می‌دهد.</p>
</div>

<div class="card">
  <h2>اطلاعات حقوقی سازمان — <?= e((string)($editOrganization['name'] ?? '')) ?></h2>
  <?php if (count($editMemberships) > 1): ?>
    <p class="hint">این کاربر عضو <?= to_persian_digits((string)count($editMemberships)) ?> سازمان است؛ اطلاعات زیر مربوط به سازمان پیش‌فرض اوست.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_organization">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
    <div class="grid grid-2">
      <label>نام حقوقی <input type="text" name="legal_name" value="<?= e((string)$editOrgProfile['legal_name']) ?>" maxlength="190"></label>
      <label>نوع شرکت
        <select name="company_type">
          <?php foreach (PROFILE_COMPANY_TYPES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($editOrgProfile['company_type'] ?? 'unspecified') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>شماره ثبت <input type="text" name="registration_number" class="ltr" value="<?= e((string)$editOrgProfile['registration_number']) ?>" maxlength="40"></label>
      <label>شناسه ملی شرکت <input type="text" name="national_id" class="ltr" value="<?= e((string)$editOrgProfile['national_id']) ?>" maxlength="20"></label>
      <label>کد اقتصادی <input type="text" name="economic_code" class="ltr" value="<?= e((string)$editOrgProfile['economic_code']) ?>" maxlength="30"></label>
      <label>مدیرعامل <input type="text" name="ceo_name" value="<?= e((string)$editOrgProfile['ceo_name']) ?>" maxlength="160"></label>
      <label>نام پدر مدیرعامل <input type="text" name="ceo_father_name" value="<?= e((string)$editOrgProfile['ceo_father_name']) ?>" maxlength="120"></label>
      <label>کد ملی مدیرعامل <input type="text" name="ceo_national_code" class="ltr" value="<?= e((string)$editOrgProfile['ceo_national_code']) ?>" maxlength="20"></label>
      <label>تاریخ تولد مدیرعامل <?= jalali_date_select('ceo_birth_date', $editOrgProfile['ceo_birth_date'] ?? null) ?></label>
      <label>تاریخ شروع فعالیت <?= jalali_date_select('company_start_date', $editOrgProfile['company_start_date'] ?? null) ?></label>
      <label>تاریخ انقضا <?= jalali_date_select('company_expiry_date', $editOrgProfile['company_expiry_date'] ?? null, 10) ?></label>
    </div>
    <button class="btn btn-primary btn-sm">ذخیره‌ی اطلاعات سازمان</button>
  </form>
</div>

<div class="card">
  <h2>آدرس سازمان</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_address">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
    <div class="grid grid-2">
      <label>استان <input type="text" name="province" value="<?= e((string)$editAddress['province']) ?>" maxlength="60"></label>
      <label>شهر <input type="text" name="city" value="<?= e((string)$editAddress['city']) ?>" maxlength="60"></label>
      <label>خیابان <input type="text" name="street" value="<?= e((string)$editAddress['street']) ?>" maxlength="190"></label>
      <label>کوچه <input type="text" name="alley" value="<?= e((string)$editAddress['alley']) ?>" maxlength="120"></label>
      <label>پلاک <input type="text" name="building_no" class="ltr" value="<?= e((string)$editAddress['building_no']) ?>" maxlength="20"></label>
      <label>واحد <input type="text" name="unit_no" class="ltr" value="<?= e((string)$editAddress['unit_no']) ?>" maxlength="20"></label>
      <label>کد پستی <input type="text" name="postal_code" class="ltr" value="<?= e((string)$editAddress['postal_code']) ?>" maxlength="20"></label>
      <label>توضیح آدرس <input type="text" name="address_text" value="<?= e((string)($editAddress['address_text'] ?? '')) ?>" maxlength="500"></label>
    </div>
    <button class="btn btn-primary btn-sm">ذخیره‌ی آدرس</button>
  </form>
</div>

<div class="card">
  <h2>تنظیمات اعتبار سازمان</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_notifications">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
    <div class="grid grid-2">
      <label><input type="checkbox" name="low_credit_alert_enabled" value="1"<?= $editNotifications['low_credit_alert_enabled'] ? ' checked' : '' ?>> هشدار اعتبار کم</label>
      <label>آستانه‌ی اعتبار کم <input type="text" name="low_credit_threshold" class="ltr" value="<?= e((string)$editNotifications['low_credit_threshold']) ?>"></label>
      <label><input type="checkbox" name="email_alert_enabled" value="1"<?= $editNotifications['email_alert_enabled'] ? ' checked' : '' ?>> اعلان ایمیلی</label>
      <label><input type="checkbox" name="sms_alert_enabled" value="1"<?= $editNotifications['sms_alert_enabled'] ? ' checked' : '' ?>> اعلان پیامکی</label>
      <label>ایمیل اعلان <input type="text" name="alert_email" class="ltr" value="<?= e((string)$editNotifications['alert_email']) ?>" maxlength="190"></label>
      <label>موبایل اعلان <input type="text" name="alert_mobile" class="ltr" value="<?= e((string)$editNotifications['alert_mobile']) ?>" maxlength="20"></label>
    </div>
    <button class="btn btn-primary btn-sm">ذخیره‌ی تنظیمات</button>
  </form>
</div>
<?php endif; ?>

<?php
$adminDocumentSections = [['owner' => 'user', 'title' => 'مدارک فردی', 'types' => PROFILE_USER_DOCUMENT_TYPES, 'documents' => $editUserDocuments]];
if ($editOrganizationId > 0) {
    $adminDocumentSections[] = ['owner' => 'organization', 'title' => 'مدارک سازمان', 'types' => PROFILE_ORGANIZATION_DOCUMENT_TYPES, 'documents' => $editOrgDocuments];
}
?>
<?php foreach ($adminDocumentSections as $section): ?>
<div class="card">
  <h2><?= e($section['title']) ?></h2>
  <div class="table-wrap">
  <table>
    <tr><th>نوع مدرک</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($section['documents'] as $document): ?>
      <tr>
        <td><?= e(profile_document_type_label((string)$document['document_type'])) ?></td>
        <td><span class="badge badge-<?= $document['status'] === 'active' ? 'ok' : 'off' ?>"><?= $document['status'] === 'active' ? 'فعال' : 'بایگانی' ?></span></td>
        <td><?= e(jdate((string)$document['created_at'])) ?></td>
        <td>
          <div class="toolbar" style="margin:0">
            <a class="btn btn-sm" href="/profile-document.php?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener">مشاهده</a>
            <?php if ($document['status'] === 'active'): ?>
              <form method="post" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="profile_document_archive">
                <input type="hidden" name="id" value="<?= $editUserId ?>">
                <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
                <input type="hidden" name="owner" value="<?= e($section['owner']) ?>">
                <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                <button class="btn btn-sm">بایگانی</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$section['documents']): ?><tr><td colspan="4" class="empty">مدرکی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <form method="post" enctype="multipart/form-data" class="toolbar" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile_document_upload">
    <input type="hidden" name="id" value="<?= $editUserId ?>">
    <input type="hidden" name="organization_id" value="<?= $editOrganizationId ?>">
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
</div>
<?php endforeach; ?>

<div class="card">
  <h2>اطلاعات هویتی (KYC)</h2>
  <p class="hint">نام، نام‌خانوادگی و موبایل از سامانه‌ی مرکزی می‌آید (بالا نمایش داده شد). فیلدهای زیر فقط در ELLSMS نگهداری می‌شوند.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="kyc_save">
    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
    <input type="hidden" name="back" value="1">
    <div class="form-row">
      <label>نام پدر <input type="text" name="father_name" value="<?= e($editKyc['father_name']) ?>"></label>
      <label>آدرس <input type="text" name="address" value="<?= e((string)$editKyc['address']) ?>"></label>
    </div>
    <div class="form-row">
      <label>تصویر کارت ملی
        <input type="file" name="id_card_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($editKyc['id_card_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $editUser['id'] ?>&type=id_card" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
      <label>تصویر مدرک دوم (شناسنامه یا پاسپورت)
        <input type="file" name="second_doc_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($editKyc['second_doc_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $editUser['id'] ?>&type=second_doc" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی اطلاعات هویتی</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>ساخت حساب تازه</h2>
  <?php if (!$domains): ?>
    <div class="flash flash-error">هیچ دامنه‌ای (Domain) در سامانه‌ی مرکزی پیدا نشد — ساخت حساب تازه بدون دامنه ممکن نیست. یک دامنه باید ابتدا در سامانه‌ی مرکزی ساخته شود.</div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create_account">
    <div class="form-row">
      <label>نام کاربری * <input type="text" name="username" required></label>
      <label>رمز عبور * <input type="password" name="password" minlength="6" required></label>
      <label>نام * <input type="text" name="first_name" required></label>
      <label>نام‌خانوادگی * <input type="text" name="last_name" required></label>
    </div>
    <div class="form-row">
      <label>ایمیل * <input type="email" name="email" required class="ltr"></label>
      <label>موبایل * <input type="text" name="mobile" required class="ltr" placeholder="0912…"></label>
      <label>کد ملی * <input type="text" name="national_id" required maxlength="10" class="ltr" placeholder="۱۰ رقم"></label>
      <label>جنسیت
        <select name="gender">
          <option value="MALE">مرد</option>
          <option value="FEMALE">زن</option>
        </select>
      </label>
    </div>
    <div class="form-row">
      <label>دامنه (Domain) *
        <select name="domain_id" required>
          <?php foreach ($domains as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>سقف ارسال روزانه <input type="number" name="daily_limit" value="1000" min="1"></label>
    </div>
    <label>نوع حساب
      <span class="segmented" role="radiogroup" aria-label="نوع حساب" style="margin-top:6px">
        <?php foreach (PROFILE_ACCOUNT_TYPES as $value => $label): ?>
          <input type="radio" id="new_account_type_<?= e($value) ?>" name="account_type" value="<?= e($value) ?>"<?= $value === 'individual' ? ' checked' : '' ?>>
          <label for="new_account_type_<?= e($value) ?>"><?= e($label) ?></label>
        <?php endforeach; ?>
      </span>
    </label>
    <button class="btn btn-primary">ساخت حساب</button>
  </form>
  <p class="hint">پس از ساخت، دسترسی ELLSMS به‌طور خودکار فعال می‌شود و می‌توانید بلافاصله اعتبار، شماره، و اطلاعات هویتی برای آن تنظیم کنید. کد کاربری (code) به‌صورت خودکار و یکتا تولید می‌شود.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>یا دادن دسترسی به یک حساب موجود</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="grant">
    <label>نام کاربری <input type="text" name="username" required placeholder="نام کاربری موجود"></label>
    <button class="btn btn-primary">دادن دسترسی</button>
  </form>
  <p class="hint">اگر حساب از قبل در سامانه‌ی مرکزی وجود دارد (مثلاً از طریق ابزارهای دیگر ساخته شده)، به‌جای ساخت دوباره، فقط دسترسی ELLSMS را برایش فعال کنید.</p>
</div>

<div class="card">
  <h2>حساب‌های دارای دسترسی ELLSMS</h2>
  <form method="post" onsubmit="return confirm('ورود دومرحله‌ای با پیامک برای همه‌ی کاربران فعال شود؟')" style="margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="enable_2fa_all">
    <button class="btn btn-sm">فعال‌سازی ورود دومرحله‌ای برای همه</button>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>نام کاربری</th><th>نام</th><th>نقش</th><th>نوع حساب</th><th>خط</th><th>اعتبار</th><th>ارسال‌شده</th><th>۲مرحله‌ای</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($panelUsers as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
        <td><span class="badge badge-<?= $u['is_admin'] ? 'admin' : 'user' ?>"><?= $u['is_admin'] ? 'مدیر' : 'کاربر' ?></span></td>
        <td>
          <?php if ($u['account_type'] === null): ?>
            <span class="badge badge-off" title="این کاربر عضو هیچ سازمانی نیست">بدون سازمان</span>
          <?php else: ?>
            <span class="badge badge-<?= $u['account_type'] === 'legal' ? 'admin' : 'user' ?>"><?= e(profile_account_type_label($u['account_type'])) ?></span>
          <?php endif; ?>
        </td>
        <td class="msisdn"><?= e((string)$u['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((float)$u['credit'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$u['sent_count'])) ?></td>
        <td><span class="badge badge-<?= $u['twofa_enabled'] ? 'ok' : 'off' ?>"><?= $u['twofa_enabled'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td><span class="badge badge-<?= ($u['active'] && !$u['deleted']) ? 'ok' : 'off' ?>"><?= ($u['active'] && !$u['deleted']) ? 'فعال' : 'غیرفعال' ?></span></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="/users.php?edit=<?= $u['id'] ?>">ویرایش</a>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('دسترسی ELLSMS برای <?= e($u['username']) ?> لغو شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="revoke">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-danger">لغو</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$panelUsers): ?><tr><td colspan="10" class="empty">هنوز حسابی وجود ندارد — از بالا دسترسی بدهید.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
