<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Proves that an over-quota bulk job ROLLS BACK its own job row — the one assertion that cannot be
 * made from inside IntegrationTestCase.
 *
 * Deliberately does NOT extend IntegrationTestCase: that base class wraps every test in an open
 * transaction, and db_transaction() (app/bootstrap.php) intentionally JOINS an already-open
 * transaction rather than nesting — only the outermost caller commits or rolls back. So while
 * IntegrationTestCase holds a transaction open, bulk_queue_job()'s internal rollback physically
 * cannot fire, and a test there would be asserting the harness's behavior rather than the
 * product's. This class manages its own committed data instead, the same reasoning
 * WalletConcurrencyTest's docblock already documents for its own case.
 */
final class CostPreviewBulkRollbackTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];
    private ?int $organizationId = null;
    private ?int $planId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
        putenv('BILLING_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        try {
            if ($this->organizationId !== null) {
                $orgId = $this->organizationId;
                $this->db?->prepare('DELETE bi FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bj.organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_bulk_jobs WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_usage_reservations WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_usage_counters WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscription_events WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscriptions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_reservations WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
            }
            if ($this->planId !== null) {
                $this->db?->prepare('DELETE FROM ellsms_plan_limits WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plan_entitlements WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plans WHERE id = ?')->execute([$this->planId]);
            }
        } finally {
            foreach ($this->createdUserIds as $userId) {
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }

    public function testAnOverQuotaBulkJobRollsBackItsOwnJobRow(): void
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['cprb_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '5000']);
        $this->createdUserIds[] = $userId;

        $org = create_organization($userId, 'Bulk Rollback Org ' . bin2hex(random_bytes(3)));
        $this->organizationId = (int)$org['organization_id'];
        $this->db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100000 WHERE user_id = ?')->execute([$userId]);

        $code = 'rb_' . bin2hex(random_bytes(4));
        $this->db->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days) VALUES (?,?, 'active',0,1,'none',0,'IRR',0)")
            ->execute([$code, $code]);
        $this->planId = (int)$this->db->lastInsertId();
        $ent = $this->db->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)');
        foreach (\Entitlements::all() as $key) {
            $ent->execute([$this->planId, $key]);
        }
        $lim = $this->db->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,?,\'hard\')');
        $lim->execute([$this->planId, \Limits::MONTHLY_MESSAGES, 10, 'monthly']);
        $lim->execute([$this->planId, \Limits::DAILY_MESSAGES, 10, 'daily']);
        subscription_create($this->organizationId, $this->planId, 'active', $userId);

        $actor = ['id' => $userId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => '5000'];

        // A preview correctly says this will not fit.
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['mobile' => '98919' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), 'content' => 'کوتاه'];
        }
        $preview = estimate_bulk_cost($actor, '5000', $items);
        $this->assertTrue($preview['ok']);
        $this->assertFalse($preview['quota']['sufficient'], 'the preview must warn that 20 exceeds the 10-message allowance');

        $jobsBefore = (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_bulk_jobs WHERE organization_id = ' . $this->organizationId)->fetch()['c'];

        // Confirming anyway must roll the whole thing back.
        [$ok, , $jobId, $reason] = bulk_queue_job($actor, 'p2p', 'Rollback Job', '5000', null, $items);

        $this->assertFalse($ok);
        $this->assertSame('quota_exceeded', $reason);
        $this->assertNull($jobId);
        $this->assertSame(
            $jobsBefore,
            (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_bulk_jobs WHERE organization_id = ' . $this->organizationId)->fetch()['c'],
            'the job row itself must be rolled back — an over-quota job must never be left queued'
        );
        $this->assertSame(0, organization_usage($this->organizationId, \Limits::MONTHLY_MESSAGES)['used']);
    }
}
