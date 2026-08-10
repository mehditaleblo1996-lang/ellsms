<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Customer/organization profile against real MySQL (docs/customer-profile.md).
 *
 * The claim this file exists to prove is an OWNERSHIP claim, not a CRUD one: personal data belongs
 * to the user and company data belongs to the organization. Every interesting test below is a
 * consequence of that — a second member seeing the same company, one user in two organizations
 * seeing two different ones, and neither being able to reach the third.
 */
final class CustomerProfileTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $memberId;
    private int $organizationId;

    /** @var list<string> files present before this test ran; anything new is cleaned up afterwards */
    private array $documentFilesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        // Rows written by these tests roll back with the transaction; FILES do not. Without this the
        // suite would leave real (if synthetic) documents in storage/profile-documents/, which
        // profile-integrity-check correctly reports as personal data with no owner.
        $this->documentFilesBefore = is_dir(profile_document_dir())
            ? (array)(glob(profile_document_dir() . '/*') ?: [])
            : [];
        $this->ownerId = $this->makeUser();
        $this->memberId = $this->makeUser();
        $result = create_organization($this->ownerId, 'Profile Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        $this->addMember($this->organizationId, $this->memberId, 'member');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ((array)(glob(profile_document_dir() . '/*') ?: []) as $path) {
            if (!in_array($path, $this->documentFilesBefore, true)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function addMember(int $organizationId, int $userId, string $role): void
    {
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?, 'active')")
            ->execute([$organizationId, $userId, $role]);
    }

    /** A real uploaded-file fixture, so validation runs against actual bytes rather than a stub array. */
    private function fakeUpload(string $field, string $bytes, string $filename, ?string $overrideTmp = null): string
    {
        $tmp = $overrideTmp ?? (sys_get_temp_dir() . '/prof_' . bin2hex(random_bytes(6)));
        file_put_contents($tmp, $bytes);
        $_FILES[$field] = [
            'name' => $filename, 'type' => 'application/octet-stream', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes),
        ];
        return $tmp;
    }

    /** Smallest valid PNG — real bytes, so mime_content_type() genuinely identifies it. */
    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /* ================= Ownership (Invariant A/B/C/D) ================= */

    public function testPersonalProfileBelongsToTheUserAndCompanyProfileToTheOrganization(): void
    {
        profile_user_save($this->ownerId, ['father_name' => 'پدرِ مالک', 'national_code' => '1234567890'], $this->ownerId);
        profile_organization_save($this->organizationId, ['legal_name' => 'شرکت آزمایشی', 'company_type' => 'legal_entity'], $this->ownerId);

        $this->assertSame('پدرِ مالک', profile_user_get($this->ownerId)['father_name']);
        $this->assertSame('', profile_user_get($this->memberId)['father_name'], 'a personal profile is not shared with anyone');
        $this->assertSame('شرکت آزمایشی', profile_organization_get($this->organizationId)['legal_name']);
    }

    public function testASecondMemberOfTheSameOrganizationSeesTheSameCompanyProfile(): void
    {
        // Invariant C. This is the property that makes company data organization-owned rather than
        // "the owner's data that others happen to read".
        profile_organization_save($this->organizationId, ['legal_name' => 'شرکت مشترک'], $this->ownerId);
        profile_address_save($this->organizationId, ['city' => 'تهران', 'postal_code' => '1234567890'], $this->ownerId);

        $asOwner  = profile_organization_get((int)current_organization_id_for($this->ownerId, $this->organizationId));
        $asMember = profile_organization_get((int)current_organization_id_for($this->memberId, $this->organizationId));
        $this->assertSame($asOwner, $asMember);
        $this->assertSame('تهران', profile_address_get($this->organizationId)['city']);
    }

    public function testOneUserInTwoOrganizationsSeesTwoDifferentCompanyProfiles(): void
    {
        // Invariant D, and the hard multi-membership criterion (STEP 44).
        $second = create_organization($this->ownerId, 'Second Org ' . bin2hex(random_bytes(3)));
        $secondOrganizationId = (int)$second['organization_id'];

        profile_organization_save($this->organizationId, ['legal_name' => 'شرکت اول', 'company_type' => 'legal_entity'], $this->ownerId);
        profile_organization_save($secondOrganizationId, ['legal_name' => 'شرکت دوم', 'company_type' => 'government'], $this->ownerId);
        profile_address_save($this->organizationId, ['city' => 'تهران'], $this->ownerId);
        profile_address_save($secondOrganizationId, ['city' => 'اصفهان'], $this->ownerId);
        profile_notifications_save($this->organizationId, ['low_credit_alert_enabled' => 1, 'low_credit_threshold' => '100'], $this->ownerId);
        profile_notifications_save($secondOrganizationId, ['low_credit_alert_enabled' => 1, 'low_credit_threshold' => '900'], $this->ownerId);

        $this->assertSame('شرکت اول', profile_organization_get($this->organizationId)['legal_name']);
        $this->assertSame('شرکت دوم', profile_organization_get($secondOrganizationId)['legal_name']);
        $this->assertSame('تهران', profile_address_get($this->organizationId)['city']);
        $this->assertSame('اصفهان', profile_address_get($secondOrganizationId)['city']);
        $this->assertSame(100, (int)profile_notifications_get($this->organizationId)['low_credit_threshold']);
        $this->assertSame(900, (int)profile_notifications_get($secondOrganizationId)['low_credit_threshold']);

        // ...while the PERSONAL profile is the same person in both. That asymmetry is the whole model.
        profile_user_save($this->ownerId, ['father_name' => 'یک نفر'], $this->ownerId);
        $this->assertSame('یک نفر', profile_user_get($this->ownerId)['father_name']);
    }

    /* ================= Validation (STEP 47/48) ================= */

    public function testNationalCodesAreNormalizedFromPersianDigitsAndMustBeTenDigits(): void
    {
        $this->assertSame('1234567890', profile_normalize_national_code('۱۲۳۴۵۶۷۸۹۰'));
        $this->assertSame('1234567890', profile_normalize_national_code(' 123-456-7890 '));
        $this->assertSame('', profile_normalize_national_code('12345'), 'too short is stored as empty, never truncated into a wrong value');
        $this->assertSame('', profile_normalize_national_code('123456789012'));

        $rejected = profile_user_save($this->ownerId, ['national_code' => '123'], $this->ownerId);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('invalid_national_code', $rejected['reason']);

        // An EMPTY value is not an error — it is "not provided yet", which most profiles are.
        $this->assertTrue(profile_user_save($this->ownerId, ['national_code' => ''], $this->ownerId)['ok']);
    }

    public function testPostalCodesFollowTheSameRule(): void
    {
        $this->assertSame('1234567890', profile_normalize_postal_code('۱۲۳۴۵ ۶۷۸۹۰'));
        $rejected = profile_address_save($this->organizationId, ['postal_code' => '123'], $this->ownerId);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('invalid_postal_code', $rejected['reason']);
    }

    public function testACompanyCannotExpireBeforeItStarts(): void
    {
        $result = profile_organization_save($this->organizationId, [
            'company_start_date' => '2026-01-01', 'company_expiry_date' => '2025-01-01',
        ], $this->ownerId);
        $this->assertFalse($result['ok']);
        $this->assertSame('expiry_before_start', $result['reason']);
    }

    public function testALegalRepresentativeMustBeAMemberOfTheOrganizationTheyRepresent(): void
    {
        $stranger = $this->makeUser();
        $result = profile_organization_save($this->organizationId, ['legal_representative_user_id' => $stranger], $this->ownerId);
        $this->assertFalse($result['ok']);
        $this->assertSame('representative_not_a_member', $result['reason']);

        $this->assertTrue(profile_organization_save($this->organizationId, ['legal_representative_user_id' => $this->memberId], $this->ownerId)['ok']);
        $this->assertSame($this->memberId, (int)profile_organization_get($this->organizationId)['legal_representative_user_id']);
    }

    public function testUnknownCompanyTypesAndGendersFallBackToUnspecifiedRatherThanBeingStored(): void
    {
        profile_organization_save($this->organizationId, ['company_type' => 'sole_wizard'], $this->ownerId);
        $this->assertSame('unspecified', profile_organization_get($this->organizationId)['company_type']);

        profile_user_save($this->ownerId, ['gender' => 'other-thing'], $this->ownerId);
        $this->assertSame('unspecified', profile_user_get($this->ownerId)['gender'], 'gender is never inferred and never guessed');
    }

    public function testPersianNamesAndMarkupAreHandledSafely(): void
    {
        profile_organization_save($this->organizationId, ['legal_name' => '<script>alert(1)</script> شرکت پارس'], $this->ownerId);
        $stored = profile_organization_get($this->organizationId)['legal_name'];
        $this->assertStringNotContainsString('<script>', $stored, 'markup is stripped at the boundary, not merely escaped at render time');
        $this->assertStringContainsString('شرکت پارس', $stored, 'and Persian text survives intact');
    }

    /* ================= Notification preferences (STEP 12) ================= */

    public function testTheLowCreditThresholdIsConfigurationAndNeverTouchesTheWallet(): void
    {
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 4321 WHERE user_id = ?')->execute([$this->ownerId]);
        profile_notifications_save($this->organizationId, [
            'low_credit_alert_enabled' => 1, 'low_credit_threshold' => '۲۵۰', 'email_alert_enabled' => 1, 'alert_email' => 'ops@example.test',
        ], $this->ownerId);

        $preferences = profile_notifications_get($this->organizationId);
        $this->assertSame(250, (int)$preferences['low_credit_threshold'], 'Persian digits normalize like everywhere else');
        $this->assertSame('ops@example.test', $preferences['alert_email']);
        $this->assertSame(4321, (int)wallet_balance($this->ownerId)['available'], 'a threshold is a setting, not a balance');
    }

    public function testAnUnusableAlertEmailIsRejected(): void
    {
        $result = profile_notifications_save($this->organizationId, ['alert_email' => 'not-an-email'], $this->ownerId);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_email', $result['reason']);
    }

    /* ================= Documents (STEP 14–19, 29, 30) ================= */

    public function testAnUploadedDocumentIsStoredOutsideTheWebRootWithAnOpaqueName(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'my id card.png');
        $result = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
        $this->assertTrue($result['ok']);

        $document = profile_document_find((int)$result['document_id']);
        $this->assertSame('image/png', $document['mime_type']);
        $this->assertSame('my id card.png', $document['original_filename'], 'the original name is kept for display');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $document['storage_key'],
            'the STORED name is opaque and contains nothing the uploader supplied');

        $path = profile_document_path((string)$document['storage_key']);
        $this->assertNotNull($path);
        $this->assertStringNotContainsString('/public/', $path, 'documents must never live under the web root');
        $this->assertSame(hash_file('sha256', $path), $document['sha256']);
    }

    public function testRealMimeInspectionRejectsAFileWhoseNameLies(): void
    {
        // A PHP payload named .png. The extension is irrelevant — the CONTENT decides.
        $this->fakeUpload('doc', "<?php system(\$_GET['c']); ?>", 'harmless.png');
        $this->expectException(\ProfileDocumentException::class);
        profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
    }

    public function testAnHtmlOrSvgPayloadIsRejected(): void
    {
        $this->fakeUpload('doc', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg');
        $this->expectException(\ProfileDocumentException::class);
        profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
    }

    public function testAnOversizedFileIsRejected(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'big.png');
        $_FILES['doc']['size'] = PROFILE_DOCUMENT_MAX_BYTES + 1;
        $this->expectException(\ProfileDocumentException::class);
        profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
    }

    public function testATraversalFilenameCannotEscapeTheStorageDirectory(): void
    {
        // The filename never reaches the filesystem at all, so this succeeds and stores an opaque
        // key — the traversal attempt simply has nowhere to land.
        $this->fakeUpload('doc', $this->pngBytes(), '../../../../etc/passwd.png');
        $result = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
        $document = profile_document_find((int)$result['document_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $document['storage_key']);
        $this->assertFileExists(profile_document_dir() . '/' . $document['storage_key']);
    }

    public function testAMalformedStorageKeyNeverResolvesToAPath(): void
    {
        foreach (['../../etc/passwd', 'abc.png', str_repeat('a', 40) . '.exe', ''] as $key) {
            $this->assertNull(profile_document_path($key), "must not resolve: {$key}");
        }
    }

    public function testAUserDocumentTypeCannotBeFiledAgainstAnOrganization(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'x.png');
        $this->expectException(\ProfileDocumentException::class);
        profile_document_store(['organization' => $this->organizationId], 'national_card', 'doc', $this->ownerId);
    }

    public function testADocumentMustHaveExactlyOneOwner(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'x.png');
        $this->expectException(\ProfileDocumentException::class);
        profile_document_store(['user' => $this->ownerId, 'organization' => $this->organizationId], 'national_card', 'doc', $this->ownerId);
    }

    public function testTheDatabaseItselfRefusesADocumentOwnedByBothOrNeither(): void
    {
        // The single-owner CHECK constraint — proven directly, because an ambiguously-owned document
        // is the direct road to a cross-tenant read.
        $this->expectException(\PDOException::class);
        db()->prepare(
            "INSERT INTO ellsms_profile_documents (organization_id, user_id, document_type, storage_key)
             VALUES (?,?, 'national_card', ?)"
        )->execute([$this->organizationId, $this->ownerId, bin2hex(random_bytes(20)) . '.png']);
    }

    public function testUploadingTheSameTypeAgainArchivesThePreviousVersionRatherThanOverwritingIt(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'first.png');
        $first = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
        $firstDocument = profile_document_find((int)$first['document_id']);

        $this->fakeUpload('doc', $this->pngBytes() . str_repeat("\0", 8), 'second.png');
        $second = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);

        $firstAfter = profile_document_find((int)$first['document_id']);
        $secondAfter = profile_document_find((int)$second['document_id']);
        $this->assertSame('archived', $firstAfter['status']);
        $this->assertNull($firstAfter['active_slot'], 'the archived version releases the slot');
        $this->assertSame('active', $secondAfter['status']);
        $this->assertNotSame($firstDocument['storage_key'], $secondAfter['storage_key'], 'the new file never overwrites the old one');
        // History survives: the previous file is still on disk and still downloadable by id.
        $this->assertFileExists(profile_document_dir() . '/' . $firstDocument['storage_key']);

        $active = profile_documents_list(['user' => $this->ownerId], false);
        $this->assertCount(1, $active, 'exactly one ACTIVE document of a type at a time');
    }

    public function testTheDatabaseRefusesTwoActiveDocumentsOfTheSameTypeForOneOwner(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'a.png');
        profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);

        $this->expectException(\PDOException::class);
        db()->prepare(
            "INSERT INTO ellsms_profile_documents (user_id, document_type, storage_key, status, active_slot)
             VALUES (?, 'national_card', ?, 'active', ?)"
        )->execute([$this->ownerId, bin2hex(random_bytes(20)) . '.png', profile_document_slot('user', $this->ownerId, 'national_card')]);
    }

    public function testArchivingRequiresTheDocumentToBelongToTheClaimedOwner(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'a.png');
        $mine = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);

        // A crafted archive request from a different owner must not touch it (STEP 43's IDOR shape).
        $result = profile_document_archive(['user' => $this->memberId], (int)$mine['document_id'], $this->memberId);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['reason']);
        $this->assertSame('active', profile_document_find((int)$mine['document_id'])['status'], 'zero mutation');
    }

    /* ================= Cross-tenant (STEP 43) ================= */

    public function testOneOrganizationCannotReachAnothersProfileOrDocuments(): void
    {
        $strangerId = $this->makeUser();
        $other = create_organization($strangerId, 'Other Org ' . bin2hex(random_bytes(3)));
        $otherOrganizationId = (int)$other['organization_id'];

        profile_organization_save($otherOrganizationId, ['legal_name' => 'شرکت دیگر'], $strangerId);
        $this->fakeUpload('doc', $this->pngBytes(), 'theirs.png');
        $theirDocument = profile_document_store(['organization' => $otherOrganizationId], 'incorporation_notice', 'doc', $strangerId);

        // Membership is the gate every organization-scoped read goes through.
        $this->assertFalse(can_access_organization($this->ownerId, $otherOrganizationId));
        $this->assertFalse(can_access_organization($this->memberId, $otherOrganizationId));

        // A crafted document id owned by the other organization is not ours, whichever owner we claim.
        $document = profile_document_find((int)$theirDocument['document_id']);
        $this->assertFalse(profile_document_belongs_to($document, 'organization', $this->organizationId));
        $this->assertFalse(profile_document_belongs_to($document, 'user', $this->ownerId));

        $this->assertSame([], profile_documents_list(['organization' => $this->organizationId]),
            "another organization's documents never appear in our list");
        $archive = profile_document_archive(['organization' => $this->organizationId], (int)$theirDocument['document_id'], $this->ownerId);
        $this->assertFalse($archive['ok']);
        $this->assertSame('active', profile_document_find((int)$theirDocument['document_id'])['status']);
    }

    /* ================= Permissions (STEP 21/22) ================= */

    public function testOnlySettingsManageMayEditTheCompanyProfile(): void
    {
        $ownerMembership  = organization_membership($this->ownerId, $this->organizationId);
        $memberMembership = organization_membership($this->memberId, $this->organizationId);

        $this->assertTrue(profile_can_manage_organization($ownerMembership), 'owner holds settings.manage');
        $this->assertFalse(profile_can_manage_organization($memberMembership), 'an ordinary member must not change the company record');

        // ...but a member may still SEE it — it is the company they belong to.
        $this->assertTrue(profile_can_view_organization($memberMembership));
        $this->assertFalse(membership_has_permission($memberMembership, Permissions::SETTINGS_MANAGE));
    }

    /* ================= Impersonation (STEP 26/45) ================= */

    public function testProfileAndDocumentMutationsAreBlockedDuringSupportImpersonation(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        $_SESSION = ['uid' => $adminId, '_created_at' => time(), '_last_activity' => time()];
        $admin = backend_find_user_by_id($adminId);
        $admin['role'] = 'admin';
        $this->assertTrue(impersonation_start($admin, $this->ownerId, 'profile support', '/users.php')['ok']);

        foreach (['profile.personal', 'profile.organization', 'profile.documents'] as $action) {
            $this->assertFalse(impersonation_action_allowed($action), "{$action} must be blocked in support mode");
        }
        // Reading is exactly what a support session is for and stays available.
        $this->assertTrue(impersonation_action_allowed('profile.view'));
    }

    /* ================= Completeness (STEP 31) ================= */

    public function testCompletenessIsInformationalAndDependsOnCompanyType(): void
    {
        $empty = profile_organization_completeness(profile_organization_get($this->organizationId), profile_address_get($this->organizationId));
        $this->assertSame(0, $empty['percent']);
        $this->assertNotEmpty($empty['missing']);

        // An individual business is not scored against a registration number it can never have.
        profile_organization_save($this->organizationId, ['company_type' => 'individual_business', 'legal_name' => 'کسب‌وکار من', 'ceo_name' => 'من'], $this->ownerId);
        profile_address_save($this->organizationId, ['city' => 'تهران', 'postal_code' => '1234567890'], $this->ownerId);
        $scored = profile_organization_completeness(profile_organization_get($this->organizationId), profile_address_get($this->organizationId));
        $this->assertSame(100, $scored['percent']);

        // The same data scored as a LEGAL ENTITY is incomplete, because more is genuinely required.
        profile_organization_save($this->organizationId, ['company_type' => 'legal_entity', 'legal_name' => 'کسب‌وکار من', 'ceo_name' => 'من'], $this->ownerId);
        $asLegalEntity = profile_organization_completeness(profile_organization_get($this->organizationId), profile_address_get($this->organizationId));
        $this->assertLessThan(100, $asLegalEntity['percent']);
    }

    /* ================= Audit & privacy (STEP 28/38) ================= */

    public function testSensitiveProfileChangesAreAuditedWithoutStoringTheIdentifier(): void
    {
        profile_user_save($this->ownerId, ['national_code' => '1234567890'], $this->ownerId);
        $row = db()->query("SELECT user_id, details FROM ellsms_audit_log WHERE action = 'profile.user_update' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame($this->ownerId, (int)$row['user_id']);
        $this->assertStringNotContainsString('1234567890', (string)$row['details'], 'the audit trail must not carry a second copy of a national code');
        $this->assertStringContainsString('12******90', (string)$row['details'], 'but enough remains to correlate');

        profile_address_save($this->organizationId, ['city' => 'تهران', 'street' => 'خیابان ولیعصر'], $this->ownerId);
        $addressRow = db()->query("SELECT details FROM ellsms_audit_log WHERE action = 'profile.address_update' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertStringNotContainsString('ولیعصر', (string)$addressRow['details'], 'an address is sensitive; the audit records that it changed, not its value');
    }

    public function testDocumentUploadsAndArchivesAreAudited(): void
    {
        $this->fakeUpload('doc', $this->pngBytes(), 'a.png');
        $result = profile_document_store(['user' => $this->ownerId], 'national_card', 'doc', $this->ownerId);
        profile_document_archive(['user' => $this->ownerId], (int)$result['document_id'], $this->ownerId);

        $actions = db()->query(
            "SELECT action FROM ellsms_audit_log WHERE action LIKE 'profile.document%' ORDER BY id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('profile.document_upload', $actions);
        $this->assertContains('profile.document_archive', $actions);
    }

    public function testTheIdentifierMaskKeepsNothingUsable(): void
    {
        $this->assertSame('12******90', profile_mask_identifier('1234567890'));
        $this->assertSame('****', profile_mask_identifier('1234'));
        $this->assertSame('', profile_mask_identifier(''));
    }

    /* ================= Backend boundary (STEP 53) ================= */

    public function testTheProfileServiceNeverTouchesTheBackendOwnedIdentityTable(): void
    {
        // Phase 8's boundary: identity stays behind the adapters. A profile page that started
        // joining user_ again would reintroduce exactly what Phase 8 removed.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Profile.php');
        $this->assertDoesNotMatchRegularExpression('/\b(FROM|JOIN|INTO|UPDATE)\s+user_\b/i', $source,
            'app/Profile.php must not query the backend-owned user_ table directly');
    }
}

/**
 * Resolves the organization id a given user would see, asserting they can actually reach it — used
 * by the "same company for every member" test so it reads as the product behaviour it describes
 * rather than as a bare table lookup.
 */
function current_organization_id_for(int $userId, int $organizationId): int
{
    return can_access_organization($userId, $organizationId) ? $organizationId : 0;
}
