<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 8, STEP 0 / TD-038 regression: cron/tenant-integrity-check.php's "organizations with zero
 * active owners" check used to be a `GROUP BY organization_id HAVING COUNT(*) = 0` over EXISTING
 * ellsms_organization_memberships rows — which can only ever produce a group for organization ids
 * that have AT LEAST ONE owner row, so a truly zero-owner organization could never be detected; the
 * check was a permanent, silent no-op. Fixed to the same LEFT JOIN/NOT EXISTS form
 * cron/rbac-integrity-check.php (Phase 7) already used correctly.
 *
 * Exercised as a real subprocess (same pattern as DatabaseOperationalScriptsTest) against committed
 * data, not via IntegrationTestCase's rolled-back transaction — the whole point is proving the
 * SCRIPT, run the way an operator actually runs it, now genuinely detects the condition.
 */
final class TenantIntegrityZeroOwnerRegressionTest extends TestCase
{
    private ?\PDO $db = null;
    private ?int $organizationId = null;
    private array $createdUserIds = [];
    private string $envPrefix;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
        $this->envPrefix = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')),
            escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')),
            escapeshellarg((string)getenv('BACKEND_DB_USER')),
            escapeshellarg((string)getenv('BACKEND_DB_PASS'))
        );
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) {
            $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
        }
        foreach ($this->createdUserIds as $userId) {
            $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    private function runScript(string $script): array {
        $cmd = "{$this->envPrefix} php " . escapeshellarg(dirname(__DIR__, 2) . '/cron/' . $script) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        return [implode("\n", $outputLines), $exitCode];
    }

    public function testZeroOwnerOrganizationIsNowDetectedByBothIntegrityTools(): void
    {
        $ownerId = $this->makeCommittedUser();
        $this->db->prepare("INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, 'active', ?)")
            ->execute(['Zero Owner Regression Org', 'zero-owner-regression-' . bin2hex(random_bytes(4)), $ownerId]);
        $this->organizationId = (int)$this->db->lastInsertId();

        // Deliberately seed a REVOKED owner membership and nothing active — a real zero-active-owner
        // organization, the exact state neither tool could previously detect (Phase 6's own tool)
        // or could only detect via the duplicated Phase 7 copy.
        $this->db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, 'owner', 'revoked')")
            ->execute([$this->organizationId, $ownerId]);

        [$tenantOutput, $tenantExit] = $this->runScript('tenant-integrity-check.php');
        $this->assertSame(1, $tenantExit, "tenant-integrity-check.php must now exit non-zero for a real zero-owner organization. Output:\n{$tenantOutput}");
        $this->assertStringContainsString('zero active owners', $tenantOutput);
        $this->assertMatchesRegularExpression('/FOUND \d+.*zero active owners/', $tenantOutput, "must report at least 1 finding, not the old permanent-zero no-op. Output:\n{$tenantOutput}");

        [$rbacOutput, $rbacExit] = $this->runScript('rbac-integrity-check.php');
        $this->assertSame(1, $rbacExit, "rbac-integrity-check.php must also detect the same condition (both tools now share the same authoritative query). Output:\n{$rbacOutput}");
        $this->assertMatchesRegularExpression('/FOUND \d+.*zero active owners/', $rbacOutput);
    }

    private function makeCommittedUser(): int {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['zero_owner_regr_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$userId, '']);
        $this->createdUserIds[] = $userId;
        return $userId;
    }
}
