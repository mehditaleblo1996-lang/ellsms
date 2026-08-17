<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure (no database) half of the KYC workflow: the state-machine transition table
 * (KYC_TRANSITIONS), the digit/identifier normalization helpers, account-type completeness scoring,
 * feature-gate defaults, and allowed-IP validation. Every one of these is a plain function over
 * already-fetched arrays/strings, which is exactly what makes it unit-testable without a real
 * database — the DB-touching half (kyc_transition(), kyc_submit(), document review) is covered by
 * tests/Integration/KycWorkflowIntegrationTest.php instead.
 */
final class KycWorkflowTest extends TestCase
{
    /* ---------- State machine ---------- */

    public function testDraftMayOnlyMoveToSubmitted(): void
    {
        $this->assertSame(['submitted'], \KYC_TRANSITIONS['draft']);
    }

    public function testUnderReviewMayMoveToApprovedNeedsCorrectionOrRejected(): void
    {
        $this->assertEqualsCanonicalizing(['approved', 'needs_correction', 'rejected'], \KYC_TRANSITIONS['under_review']);
    }

    public function testApprovedIsTerminal(): void
    {
        $this->assertSame([], \KYC_TRANSITIONS['approved']);
    }

    public function testNeedsCorrectionAndRejectedBothReturnToSubmitted(): void
    {
        $this->assertSame(['submitted'], \KYC_TRANSITIONS['needs_correction']);
        $this->assertSame(['submitted'], \KYC_TRANSITIONS['rejected']);
    }

    /** Every status in the catalog has a (possibly empty) transition list — nothing is undefined. */
    public function testEveryStatusHasATransitionEntry(): void
    {
        foreach (array_keys(\KYC_STATUSES) as $status) {
            $this->assertArrayHasKey($status, \KYC_TRANSITIONS);
        }
    }

    /** An invalid/arbitrary transition (e.g. draft -> approved, skipping review) must not be listed. */
    public function testDraftCannotJumpDirectlyToApproved(): void
    {
        $this->assertNotContains('approved', \KYC_TRANSITIONS['draft']);
    }

    public function testSubmittedCannotJumpDirectlyToApproved(): void
    {
        $this->assertNotContains('approved', \KYC_TRANSITIONS['submitted']);
    }

    public function testStatusLabelsAreAllPersian(): void
    {
        foreach (\KYC_STATUSES as $label) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $label);
        }
    }

    /* ---------- Feature gating (§22) ---------- */

    public function testUnknownGateIsNeverRequired(): void
    {
        $this->assertFalse(\kyc_gate_required('not_a_real_gate'));
    }

    public function testGateOffAlwaysAllowsRegardlessOfKycStatus(): void
    {
        $this->assertTrue(\kyc_feature_allowed_for_status(false, 'draft'));
        $this->assertTrue(\kyc_feature_allowed_for_status(false, 'rejected'));
        $this->assertTrue(\kyc_feature_allowed_for_status(false, 'approved'));
    }

    public function testGateOnDeniesUntilApproved(): void
    {
        $this->assertFalse(\kyc_feature_allowed_for_status(true, 'draft'));
        $this->assertFalse(\kyc_feature_allowed_for_status(true, 'submitted'));
        $this->assertFalse(\kyc_feature_allowed_for_status(true, 'under_review'));
        $this->assertFalse(\kyc_feature_allowed_for_status(true, 'needs_correction'));
        $this->assertFalse(\kyc_feature_allowed_for_status(true, 'rejected'));
    }

    public function testGateOnAllowsOnceApproved(): void
    {
        $this->assertTrue(\kyc_feature_allowed_for_status(true, 'approved'));
    }

    public function testEveryCatalogedGateHasAPersianLabel(): void
    {
        foreach (\KYC_FEATURE_GATES as $label) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $label);
        }
    }

    /* ---------- Account type catalog (§1) ---------- */

    public function testAccountTypesAreExactlyIndividualAndLegal(): void
    {
        $this->assertSame(['individual', 'legal'], array_keys(\PROFILE_ACCOUNT_TYPES));
    }

    public function testUnknownAccountTypeLabelFallsBackToIndividual(): void
    {
        $this->assertSame(\PROFILE_ACCOUNT_TYPES['individual'], \profile_account_type_label('not_a_type'));
    }

    /* ---------- Account-level completeness (§14) ---------- */

    public function testIndividualCompletenessIsZeroWhenNothingFilled(): void
    {
        $userProfile = ['father_name' => '', 'national_code' => '', 'birth_certificate_no' => '', 'birth_date' => null];
        $address = ['postal_code' => '', 'city' => ''];
        $score = \profile_account_completeness('individual', $userProfile, [], $address);
        $this->assertSame(0, $score['percent']);
    }

    public function testIndividualCompletenessIsFullWhenEverythingFilled(): void
    {
        $userProfile = ['father_name' => 'رضا', 'national_code' => '1234567890', 'birth_certificate_no' => '1', 'birth_date' => '2000-01-01'];
        $address = ['postal_code' => '1234567890', 'city' => 'تهران'];
        $score = \profile_account_completeness('individual', $userProfile, [], $address);
        $this->assertSame(100, $score['percent']);
        $this->assertSame([], $score['missing']);
    }

    public function testLegalCompletenessDelegatesToOrganizationCompleteness(): void
    {
        $organizationProfile = ['legal_name' => 'شرکت نمونه', 'ceo_name' => 'مدیر', 'company_type' => 'unspecified'];
        $address = ['postal_code' => '1234567890', 'city' => 'تهران'];
        $score = \profile_account_completeness('legal', [], $organizationProfile, $address);
        $this->assertSame(100, $score['percent']);
    }

    /* ---------- Digit normalization (§26/§27) — reused straight from Profile.php ---------- */

    public function testPersianDigitsNormalizeToAsciiForNationalCode(): void
    {
        $this->assertSame('1234567890', \profile_normalize_national_code('۱۲۳۴۵۶۷۸۹۰'));
    }

    public function testArabicDigitsNormalizeToAsciiForPostalCode(): void
    {
        $this->assertSame('1234567890', \profile_normalize_postal_code('١٢٣٤٥٦٧٨٩٠'));
    }

    public function testShortNationalCodeIsRejectedRatherThanTruncated(): void
    {
        $this->assertSame('', \profile_normalize_national_code('123'));
    }

    /* ---------- Allowed-IP validation (§20) ---------- */

    public function testPlainIpv4IsValid(): void
    {
        $this->assertSame('203.0.113.10', \allowed_ip_normalize('203.0.113.10'));
    }

    public function testIpv4CidrIsValid(): void
    {
        $this->assertSame('203.0.113.0/24', \allowed_ip_normalize('203.0.113.0/24'));
    }

    public function testIpv6IsValid(): void
    {
        $this->assertSame('2001:db8::1', \allowed_ip_normalize('2001:db8::1'));
    }

    public function testIpv4CidrPrefixOutOfRangeIsRejected(): void
    {
        $this->assertNull(\allowed_ip_normalize('203.0.113.0/99'));
    }

    public function testGarbageIsRejected(): void
    {
        $this->assertNull(\allowed_ip_normalize('not-an-ip'));
        $this->assertNull(\allowed_ip_normalize(''));
    }

    public function testPersianDigitsInIpAreNormalizedBeforeValidation(): void
    {
        $this->assertSame('192.168.1.1', \allowed_ip_normalize('۱۹۲.۱۶۸.۱.۱'));
    }
}
