<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Phase 12 — the DB-backed half of app/ApiKeys.php against a real ellsms_api_keys table: creation
 * stores only a hash (never the raw secret), authentication fail-closed for every wrong/revoked/
 * expired/cross-organization case, revocation is immediately effective (no cache), and rotation
 * invalidates the old secret. Mirrors tests/Integration/RbacTest.php's own
 * makeOrganization()/addMember() fixture style.
 */
final class ApiKeyLifecycleTest extends IntegrationTestCase
{
    private function makeOrganization(string $name): array {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    public function testCreateReturnsRawKeyOnceButPersistsOnlyAHash(): void
    {
        $org = $this->makeOrganization('Org A');
        $result = api_key_create($org['organization_id'], $org['owner_id'], 'CI key', [\ApiScopes::MESSAGES_SEND]);
        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('ellsms_live_', $result['raw_key']);

        $row = db()->query('SELECT secret_hash FROM ellsms_api_keys WHERE id = ' . (int)$result['id'])->fetch();
        $this->assertNotFalse($row);
        $this->assertStringNotContainsString($result['raw_key'], $row['secret_hash']);
        $this->assertSame(64, strlen($row['secret_hash']), 'stored value is a hex SHA-256 digest, not the secret itself');
    }

    public function testCreateRejectsInvalidScope(): void
    {
        $org = $this->makeOrganization('Org B');
        $result = api_key_create($org['organization_id'], $org['owner_id'], 'bad', ['platform:admin']);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_scopes', $result['reason']);
    }

    public function testAuthenticateSucceedsWithAFreshValidKey(): void
    {
        $org = $this->makeOrganization('Org C');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);
        $principal = api_key_authenticate($created['raw_key']);
        $this->assertNotNull($principal);
        $this->assertSame($org['organization_id'], $principal['organization_id']);
        $this->assertSame($org['owner_id'], $principal['created_by_user_id']);
        $this->assertSame([\ApiScopes::BALANCE_READ], $principal['scopes']);
    }

    public function testAuthenticateFailsWithWrongSecret(): void
    {
        $org = $this->makeOrganization('Org D');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);
        $tampered = substr($created['raw_key'], 0, -1) . (substr($created['raw_key'], -1) === 'a' ? 'b' : 'a');
        $this->assertNull(api_key_authenticate($tampered));
    }

    public function testAuthenticateFailsForUnknownPrefix(): void
    {
        $this->assertNull(api_key_authenticate('ellsms_live_' . bin2hex(random_bytes(6)) . '_' . api_key_generate_secret()));
    }

    public function testAuthenticateFailsForMalformedKeyShape(): void
    {
        $this->assertNull(api_key_authenticate('totally-not-a-key'));
        $this->assertNull(api_key_authenticate(''));
    }

    public function testRevocationTakesEffectImmediately(): void
    {
        $org = $this->makeOrganization('Org E');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);
        $this->assertNotNull(api_key_authenticate($created['raw_key']));

        $revoke = api_key_revoke($org['organization_id'], $created['id'], $org['owner_id']);
        $this->assertTrue($revoke['ok']);

        $this->assertNull(api_key_authenticate($created['raw_key']), 'a revoked key must fail authentication on the very next call, no caching');
    }

    public function testExpiredKeyFailsAuthenticationEvenIfNeverRevoked(): void
    {
        $org = $this->makeOrganization('Org F');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ], 'live', '2000-01-01 00:00:00');
        $this->assertNull(api_key_authenticate($created['raw_key']));
    }

    public function testRotateInvalidatesOldSecretAndIssuesAWorkingNewOne(): void
    {
        $org = $this->makeOrganization('Org G');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::MESSAGES_SEND]);
        $rotated = api_key_rotate($org['organization_id'], $created['id'], $org['owner_id']);
        $this->assertTrue($rotated['ok']);
        $this->assertNotSame($created['raw_key'], $rotated['raw_key']);

        $this->assertNull(api_key_authenticate($created['raw_key']), 'old secret must stop working immediately after rotation');
        $principal = api_key_authenticate($rotated['raw_key']);
        $this->assertNotNull($principal);
        $this->assertSame([\ApiScopes::MESSAGES_SEND], $principal['scopes'], 'rotation preserves the original scopes');
    }

    public function testApiKeyFromOneOrganizationNeverResolvesToAnotherOrganization(): void
    {
        $orgA = $this->makeOrganization('Org H');
        $orgB = $this->makeOrganization('Org I');
        $createdA = api_key_create($orgA['organization_id'], $orgA['owner_id'], 'k', [\ApiScopes::CONTACTS_READ]);

        $principal = api_key_authenticate($createdA['raw_key']);
        $this->assertSame($orgA['organization_id'], $principal['organization_id']);
        $this->assertNotSame($orgB['organization_id'], $principal['organization_id']);

        // Defense in depth: a lookup scoped to the WRONG organization id must never find this key.
        $this->assertNull(api_key_find($orgB['organization_id'], $createdA['id']));
        $this->assertNotNull(api_key_find($orgA['organization_id'], $createdA['id']));
    }

    public function testAuthenticateFailsWhenOrganizationIsDisabled(): void
    {
        $org = $this->makeOrganization('Org J');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);
        db()->prepare("UPDATE ellsms_organizations SET status = 'disabled' WHERE id = ?")->execute([$org['organization_id']]);
        $this->assertNull(api_key_authenticate($created['raw_key']));
    }

    public function testSuspendedOrganizationStillAuthenticatesButIsFlaggedSuspended(): void
    {
        // Suspended is a softer state than disabled (mirrors require_active_organization()'s own
        // distinction) — authentication itself still succeeds so read-only endpoints keep working;
        // write endpoints are the ones that must separately reject based on organization_status.
        $org = $this->makeOrganization('Org K');
        $created = api_key_create($org['organization_id'], $org['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);
        db()->prepare("UPDATE ellsms_organizations SET status = 'suspended' WHERE id = ?")->execute([$org['organization_id']]);
        $principal = api_key_authenticate($created['raw_key']);
        $this->assertNotNull($principal);
        $this->assertSame('suspended', $principal['organization_status']);
    }
}
