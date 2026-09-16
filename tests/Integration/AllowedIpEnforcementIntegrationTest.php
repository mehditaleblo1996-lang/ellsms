<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AllowedIpEnforcementIntegrationTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $organizationId;
    private int $otherOwnerId;
    private int $otherOrganizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->makeUser();
        $org = \create_organization($this->ownerId, 'IP Allowlist Org ' . bin2hex(random_bytes(3)));
        self::assertTrue($org['ok']);
        $this->organizationId = (int)$org['organization_id'];

        $this->otherOwnerId = $this->makeUser();
        $other = \create_organization($this->otherOwnerId, 'IP Allowlist Other ' . bin2hex(random_bytes(3)));
        self::assertTrue($other['ok']);
        $this->otherOrganizationId = (int)$other['organization_id'];
    }

    public function testDefaultDisabledPolicyPreservesExistingApiAccessEvenWithActiveRows(): void
    {
        $created = \allowed_ip_create($this->organizationId, '203.0.113.10', 'server', $this->ownerId);
        self::assertTrue($created['ok']);
        self::assertFalse(\allowed_ip_enforcement_enabled($this->organizationId));

        $decision = \allowed_ip_access_decision($this->organizationId, '198.51.100.77');
        self::assertTrue($decision['allowed']);
        self::assertFalse($decision['enabled']);
        self::assertSame('disabled', $decision['reason']);
    }

    public function testCannotEnablePolicyWithoutAtLeastOneActiveEntry(): void
    {
        $result = \allowed_ip_set_enforcement($this->organizationId, true, $this->ownerId);

        self::assertFalse($result['ok']);
        self::assertSame('empty_allowlist', $result['reason']);
        self::assertFalse(\allowed_ip_enforcement_enabled($this->organizationId));
    }

    public function testEnabledPolicyAllowsMultipleIpv4AndIpv6RulesAndDeniesOthers(): void
    {
        self::assertTrue(\allowed_ip_create($this->organizationId, '203.0.113.0/24', 'office', $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_create($this->organizationId, '2001:db8:abcd::/48', 'v6', $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_create($this->organizationId, '198.51.100.9', 'single', $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_set_enforcement($this->organizationId, true, $this->ownerId)['ok']);

        self::assertTrue(\allowed_ip_access_decision($this->organizationId, '203.0.113.55')['allowed']);
        self::assertTrue(\allowed_ip_access_decision($this->organizationId, '2001:db8:abcd::1234')['allowed']);
        self::assertTrue(\allowed_ip_access_decision($this->organizationId, '198.51.100.9')['allowed']);

        $denied = \allowed_ip_access_decision($this->organizationId, '198.51.100.10');
        self::assertFalse($denied['allowed']);
        self::assertTrue($denied['enabled']);
        self::assertSame('not_allowed', $denied['reason']);
    }

    public function testMalformedCidrIsRejectedAndCanonicalDuplicateIsDetected(): void
    {
        $invalid = \allowed_ip_create($this->organizationId, '192.0.2.1/99', 'bad', $this->ownerId);
        self::assertFalse($invalid['ok']);
        self::assertSame('invalid_ip', $invalid['reason']);

        $first = \allowed_ip_create($this->organizationId, '192.0.2.44/24', 'network', $this->ownerId);
        self::assertTrue($first['ok']);
        self::assertSame('192.0.2.0/24', $first['ip_or_cidr']);

        $duplicate = \allowed_ip_create($this->organizationId, '192.0.2.99/24', 'same network', $this->ownerId);
        self::assertFalse($duplicate['ok']);
        self::assertSame('duplicate', $duplicate['reason']);
    }

    public function testPoliciesAndRulesAreTenantIsolated(): void
    {
        self::assertTrue(\allowed_ip_create($this->organizationId, '203.0.113.0/24', 'A', $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_set_enforcement($this->organizationId, true, $this->ownerId)['ok']);

        self::assertTrue(\allowed_ip_create($this->otherOrganizationId, '198.51.100.0/24', 'B', $this->otherOwnerId)['ok']);
        self::assertTrue(\allowed_ip_set_enforcement($this->otherOrganizationId, true, $this->otherOwnerId)['ok']);

        self::assertTrue(\allowed_ip_access_decision($this->organizationId, '203.0.113.5')['allowed']);
        self::assertFalse(\allowed_ip_access_decision($this->organizationId, '198.51.100.5')['allowed']);
        self::assertTrue(\allowed_ip_access_decision($this->otherOrganizationId, '198.51.100.5')['allowed']);
        self::assertFalse(\allowed_ip_access_decision($this->otherOrganizationId, '203.0.113.5')['allowed']);
    }

    public function testLastActiveRuleCannotBeDisabledOrDeletedWhilePolicyIsEnabled(): void
    {
        $first = \allowed_ip_create($this->organizationId, '203.0.113.10', 'primary', $this->ownerId);
        self::assertTrue($first['ok']);
        self::assertTrue(\allowed_ip_set_enforcement($this->organizationId, true, $this->ownerId)['ok']);

        $toggle = \allowed_ip_toggle($this->organizationId, (int)$first['id'], $this->ownerId);
        self::assertFalse($toggle['ok']);
        self::assertSame('last_active_required', $toggle['reason']);

        $delete = \allowed_ip_delete($this->organizationId, (int)$first['id'], $this->ownerId);
        self::assertFalse($delete['ok']);
        self::assertSame('last_active_required', $delete['reason']);

        $second = \allowed_ip_create($this->organizationId, '198.51.100.10', 'secondary', $this->ownerId);
        self::assertTrue($second['ok']);
        self::assertTrue(\allowed_ip_toggle($this->organizationId, (int)$first['id'], $this->ownerId)['ok']);

        $deleteLast = \allowed_ip_delete($this->organizationId, (int)$second['id'], $this->ownerId);
        self::assertFalse($deleteLast['ok']);
        self::assertSame('last_active_required', $deleteLast['reason']);

        self::assertTrue(\allowed_ip_set_enforcement($this->organizationId, false, $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_delete($this->organizationId, (int)$second['id'], $this->ownerId)['ok']);
    }

    public function testDeniedApiDecisionIsAuditedWithoutPlaintextSourceIp(): void
    {
        self::assertTrue(\allowed_ip_create($this->organizationId, '203.0.113.0/24', 'allowed', $this->ownerId)['ok']);
        self::assertTrue(\allowed_ip_set_enforcement($this->organizationId, true, $this->ownerId)['ok']);

        $decision = \allowed_ip_access_decision($this->organizationId, '198.51.100.88');
        self::assertFalse($decision['allowed']);

        \allowed_ip_record_api_denial([
            'organization_id' => $this->organizationId,
            'api_key_id' => 4242,
            'key_prefix' => 'abcdef123456',
            'created_by_user_id' => $this->ownerId,
        ], $decision);

        $st = \db()->prepare(
            "SELECT details FROM ellsms_audit_log
              WHERE user_id = ? AND action = 'api.ip_allowlist_denied'
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$this->ownerId]);
        $row = $st->fetch();

        self::assertNotFalse($row);
        self::assertStringContainsString('api_key_id=4242', (string)$row['details']);
        self::assertStringContainsString('reason=not_allowed', (string)$row['details']);
        self::assertStringContainsString(hash('sha256', '198.51.100.88'), (string)$row['details']);
        self::assertStringNotContainsString('198.51.100.88', (string)$row['details']);
        self::assertStringNotContainsString('abcdef123456', (string)$row['details'], 'audit row must not include even the non-secret key prefix unless needed');
    }
}
