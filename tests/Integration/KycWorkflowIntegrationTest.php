<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The DB-touching half of the KYC workflow (docs/profile-kyc.md): account type persistence, the
 * ellsms_kyc_requests state machine, per-document review, submission eligibility, and — critically —
 * tenant isolation across all of it. The pure logic (transition table shape, digit normalization,
 * gating decision) is covered without a database in tests/Unit/KycWorkflowTest.php.
 */
final class KycWorkflowIntegrationTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $memberId;
    private int $organizationId;
    private int $otherOwnerId;
    private int $otherOrganizationId;

    private array $documentFilesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->documentFilesBefore = is_dir(\profile_document_dir())
            ? (array)(glob(\profile_document_dir() . '/*') ?: [])
            : [];

        $this->ownerId = $this->makeUser();
        $this->memberId = $this->makeUser();
        $result = \create_organization($this->ownerId, 'KYC Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        $this->addMember($this->organizationId, $this->memberId, 'member');

        $this->otherOwnerId = $this->makeUser();
        $other = \create_organization($this->otherOwnerId, 'KYC Org B ' . bin2hex(random_bytes(3)));
        $this->otherOrganizationId = (int)$other['organization_id'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ((array)(glob(\profile_document_dir() . '/*') ?: []) as $path) {
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

    private function fakeUpload(string $field, string $bytes, string $filename): string
    {
        $tmp = sys_get_temp_dir() . '/kyc_' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $bytes);
        $_FILES[$field] = [
            'name' => $filename, 'type' => 'application/octet-stream', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes),
        ];
        return $tmp;
    }

    /** A minimal 1x1 PNG — real bytes, so mime_content_type() genuinely detects image/png. */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\xcf\xc0\x00\x00\x03\x01\x01\x00\x18\xdd\x8d\xb0\x00\x00\x00\x00IEND\xaeB`\x82";

    /* ---------- Account type ---------- */

    public function testNewOrganizationDefaultsToIndividualAccountType(): void
    {
        $profile = \profile_organization_get($this->organizationId);
        $this->assertSame('individual', $profile['account_type']);
    }

    public function testAccountTypeSwitchIsPersistedAndAudited(): void
    {
        $result = \profile_organization_save($this->organizationId, ['account_type' => 'legal'], $this->ownerId);
        $this->assertTrue($result['ok']);
        $this->assertSame('legal', \profile_organization_get($this->organizationId)['account_type']);

        $st = db()->prepare("SELECT action FROM ellsms_audit_log WHERE action = 'profile.account_type_changed' AND details LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute(["%org={$this->organizationId}%"]);
        $this->assertNotFalse($st->fetch(), 'account type switch must be audited');
    }

    public function testSwitchingAccountTypeDoesNotDeleteTheOtherSidesData(): void
    {
        \profile_organization_save($this->organizationId, ['account_type' => 'legal', 'legal_name' => 'شرکت آزمایشی'], $this->ownerId);
        \profile_user_save($this->ownerId, ['father_name' => 'رضا'], $this->ownerId);

        // Switch back to individual — the company data (legal_name) must survive, dormant.
        \profile_organization_save($this->organizationId, ['account_type' => 'individual'], $this->ownerId);
        $profile = \profile_organization_get($this->organizationId);
        $this->assertSame('individual', $profile['account_type']);
        $this->assertSame('شرکت آزمایشی', $profile['legal_name'], 'legal_name must not be silently wiped by an account_type switch');
        $this->assertSame('رضا', \profile_user_get($this->ownerId)['father_name']);
    }

    /* ---------- State machine ---------- */

    public function testFreshOrganizationStartsInDraft(): void
    {
        $this->assertSame('draft', \kyc_request_get($this->organizationId)['status']);
    }

    public function testDraftToSubmittedSucceeds(): void
    {
        $result = \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        $this->assertTrue($result['ok']);
        $request = \kyc_request_get($this->organizationId);
        $this->assertSame('submitted', $request['status']);
        $this->assertNotNull($request['submitted_at']);
    }

    public function testFullHappyPathToApproved(): void
    {
        $this->assertTrue(\kyc_transition($this->organizationId, 'submitted', $this->ownerId)['ok']);
        $this->assertTrue(\kyc_transition($this->organizationId, 'under_review', 999)['ok']);
        $this->assertTrue(\kyc_transition($this->organizationId, 'approved', 999, 'همه چیز درست است')['ok']);

        $request = \kyc_request_get($this->organizationId);
        $this->assertSame('approved', $request['status']);
        $this->assertSame(999, (int)$request['reviewed_by_user_id']);
        $this->assertNotNull($request['reviewed_at']);
    }

    public function testRejectedCanBeResubmitted(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        \kyc_transition($this->organizationId, 'under_review', 999);
        \kyc_transition($this->organizationId, 'rejected', 999, 'مدرک ناخوانا');
        $result = \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        $this->assertTrue($result['ok']);
        $this->assertSame('submitted', \kyc_request_get($this->organizationId)['status']);
    }

    public function testNeedsCorrectionCanBeResubmitted(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        \kyc_transition($this->organizationId, 'under_review', 999);
        \kyc_transition($this->organizationId, 'needs_correction', 999, 'کد ملی ناقص است');
        $this->assertTrue(\kyc_transition($this->organizationId, 'submitted', $this->ownerId)['ok']);
    }

    public function testDraftCannotJumpToApproved(): void
    {
        $result = \kyc_transition($this->organizationId, 'approved', 999);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_transition', $result['reason']);
        $this->assertSame('draft', \kyc_request_get($this->organizationId)['status'], 'a rejected transition must not mutate state');
    }

    public function testApprovedIsTerminalAndRejectsAnyFurtherTransition(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        \kyc_transition($this->organizationId, 'under_review', 999);
        \kyc_transition($this->organizationId, 'approved', 999);

        $result = \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        $this->assertFalse($result['ok']);
        $this->assertSame('approved', \kyc_request_get($this->organizationId)['status']);
    }

    public function testSubmittedCannotSkipReviewToApproved(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        $result = \kyc_transition($this->organizationId, 'approved', 999);
        $this->assertFalse($result['ok']);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $result = \kyc_transition($this->organizationId, 'totally_made_up', 999);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    /* ---------- Submission eligibility (§16) ---------- */

    public function testSubmitFailsWhenProfileAndDocumentsAreIncomplete(): void
    {
        $result = \kyc_submit(
            $this->organizationId, $this->ownerId, 'individual',
            \profile_user_get($this->ownerId), \profile_organization_get($this->organizationId), \profile_address_get($this->organizationId)
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('incomplete', $result['reason']);
        $this->assertNotEmpty($result['missing']);
        $this->assertSame('draft', \kyc_request_get($this->organizationId)['status']);
    }

    public function testSubmitSucceedsOnceIndividualProfileAddressAndDocumentsArePresent(): void
    {
        \profile_user_save($this->ownerId, ['father_name' => 'رضا', 'national_code' => '1234567890'], $this->ownerId);
        \profile_address_save($this->organizationId, ['city' => 'تهران', 'postal_code' => '1234567890'], $this->ownerId);
        foreach (\PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL as $type) {
            $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
            \profile_document_store(['user' => $this->ownerId], $type, 'document', $this->ownerId);
        }

        $result = \kyc_submit(
            $this->organizationId, $this->ownerId, 'individual',
            \profile_user_get($this->ownerId), \profile_organization_get($this->organizationId), \profile_address_get($this->organizationId)
        );
        $this->assertTrue($result['ok']);
        $this->assertSame('submitted', \kyc_request_get($this->organizationId)['status']);
    }

    public function testLegalSubmissionRequiresCompanyFieldsAndOrganizationDocuments(): void
    {
        \profile_organization_save($this->organizationId, [
            'account_type' => 'legal', 'legal_name' => 'شرکت نمونه', 'national_id' => '10861234567',
            'ceo_name' => 'مدیر عامل', 'ceo_national_code' => '0987654321',
        ], $this->ownerId);
        \profile_address_save($this->organizationId, ['city' => 'تهران', 'postal_code' => '1234567890'], $this->ownerId);

        $before = \kyc_can_submit($this->organizationId, $this->ownerId, 'legal', [], \profile_organization_get($this->organizationId), \profile_address_get($this->organizationId));
        $this->assertFalse($before['ok'], 'must still be missing organization documents');

        foreach (\PROFILE_REQUIRED_DOCUMENTS_LEGAL as $type) {
            $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
            \profile_document_store(['organization' => $this->organizationId], $type, 'document', $this->ownerId);
        }
        $after = \kyc_can_submit($this->organizationId, $this->ownerId, 'legal', [], \profile_organization_get($this->organizationId), \profile_address_get($this->organizationId));
        $this->assertTrue($after['ok']);
    }

    /* ---------- Per-document review (§9/§17) ---------- */

    public function testUploadedDocumentStartsPending(): void
    {
        $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
        $result = \profile_document_store(['user' => $this->ownerId], 'national_card', 'document', $this->ownerId);
        $document = \profile_document_find((int)$result['document_id']);
        $this->assertSame('pending', $document['review_status']);
    }

    public function testDocumentApprovalIsRecordedWithReviewer(): void
    {
        $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
        $result = \profile_document_store(['user' => $this->ownerId], 'national_card', 'document', $this->ownerId);
        $documentId = (int)$result['document_id'];

        $review = \kyc_document_review($documentId, 'approved', 999, 'واضح و معتبر');
        $this->assertTrue($review['ok']);

        $document = \profile_document_find($documentId);
        $this->assertSame('approved', $document['review_status']);
        $this->assertSame(999, (int)$document['reviewed_by_user_id']);
        $this->assertSame('واضح و معتبر', $document['review_note']);
    }

    public function testDocumentRejectionRecordsNote(): void
    {
        $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
        $result = \profile_document_store(['user' => $this->ownerId], 'national_card', 'document', $this->ownerId);
        \kyc_document_review((int)$result['document_id'], 'rejected', 999, 'تصویر تار است');
        $document = \profile_document_find((int)$result['document_id']);
        $this->assertSame('rejected', $document['review_status']);
        $this->assertSame('تصویر تار است', $document['review_note']);
    }

    public function testReplacingADocumentResetsReviewStatusToPendingOnTheNewRow(): void
    {
        $this->fakeUpload('document', self::PNG_BYTES, 'first.png');
        $first = \profile_document_store(['user' => $this->ownerId], 'national_card', 'document', $this->ownerId);
        \kyc_document_review((int)$first['document_id'], 'rejected', 999, 'ناخوانا');

        $this->fakeUpload('document', self::PNG_BYTES, 'second.png');
        $second = \profile_document_store(['user' => $this->ownerId], 'national_card', 'document', $this->ownerId);
        $newDocument = \profile_document_find((int)$second['document_id']);
        $this->assertSame('pending', $newDocument['review_status'], 'a freshly uploaded replacement must not inherit the old rejection');
    }

    /* ---------- Tenant isolation (§24) ---------- */

    public function testKycRequestsAreIsolatedPerOrganization(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        $this->assertSame('submitted', \kyc_request_get($this->organizationId)['status']);
        $this->assertSame('draft', \kyc_request_get($this->otherOrganizationId)['status'], 'a transition on one organization must never affect another');
    }

    public function testOrganizationCannotReviewAnotherOrganizationsDocumentThroughItsOwnScope(): void
    {
        $this->fakeUpload('document', self::PNG_BYTES, 'x.png');
        $result = \profile_document_store(['organization' => $this->organizationId], 'incorporation_notice', 'document', $this->ownerId);
        $document = \profile_document_find((int)$result['document_id']);

        // Mirrors the guard in public/kyc-review.php: a document must belong to the organization the
        // admin screen is scoped to before any review action is accepted.
        $this->assertFalse(\profile_document_belongs_to($document, 'organization', $this->otherOrganizationId));
        $this->assertTrue(\profile_document_belongs_to($document, 'organization', $this->organizationId));
    }

    public function testAccountTypeChangeOnOneOrganizationDoesNotAffectAnother(): void
    {
        \profile_organization_save($this->organizationId, ['account_type' => 'legal'], $this->ownerId);
        $this->assertSame('individual', \profile_organization_get($this->otherOrganizationId)['account_type']);
    }

    /* ---------- Allowed IPs (§20) ---------- */

    public function testAllowedIpCrudAndAudit(): void
    {
        $create = \allowed_ip_create($this->organizationId, '203.0.113.5', 'دفتر', $this->ownerId);
        $this->assertTrue($create['ok']);
        $ips = \allowed_ip_list($this->organizationId);
        $this->assertCount(1, $ips);
        $this->assertSame('203.0.113.5', $ips[0]['ip_or_cidr']);

        $st = db()->prepare("SELECT id FROM ellsms_audit_log WHERE action = 'allowed_ip.created' ORDER BY id DESC LIMIT 1");
        $st->execute();
        $this->assertNotFalse($st->fetch());

        $delete = \allowed_ip_delete($this->organizationId, (int)$create['id'], $this->ownerId);
        $this->assertTrue($delete['ok']);
        $this->assertCount(0, \allowed_ip_list($this->organizationId));
    }

    public function testAllowedIpIsScopedToItsOwnOrganization(): void
    {
        $create = \allowed_ip_create($this->organizationId, '203.0.113.5', '', $this->ownerId);
        $delete = \allowed_ip_delete($this->otherOrganizationId, (int)$create['id'], $this->otherOwnerId);
        $this->assertFalse($delete['ok'], 'deleting another organization\'s allowed IP by id must fail');
        $this->assertCount(1, \allowed_ip_list($this->organizationId));
    }

    public function testDuplicateAllowedIpForSameOrganizationIsRejected(): void
    {
        \allowed_ip_create($this->organizationId, '203.0.113.5', '', $this->ownerId);
        $second = \allowed_ip_create($this->organizationId, '203.0.113.5', '', $this->ownerId);
        $this->assertFalse($second['ok']);
        $this->assertSame('duplicate', $second['reason']);
    }

    /* ---------- Feature gating end-to-end (§22) ---------- */

    public function testFeatureAllowedUsesRealKycStatusForAnApprovedOrganization(): void
    {
        \kyc_transition($this->organizationId, 'submitted', $this->ownerId);
        \kyc_transition($this->organizationId, 'under_review', 999);
        \kyc_transition($this->organizationId, 'approved', 999);

        $status = \kyc_request_get($this->organizationId)['status'];
        $this->assertTrue(\kyc_feature_allowed_for_status(true, $status));
        $this->assertTrue(\kyc_feature_allowed_for_status(false, $status));
    }
}
