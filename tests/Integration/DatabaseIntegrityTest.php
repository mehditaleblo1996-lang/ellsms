<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOException;

/**
 * Phase 5 database integrity against real MySQL. The standard integration fixture
 * (IntegrationTestCase::ensureSchemaLoaded()) already applies every migration, including
 * db/migrations/2026_07_29_data_integrity.sql — so by the time any test here runs, every new
 * FK/UNIQUE constraint this phase added is already live. That means:
 *
 *  - Constraint REJECTION (STEP 20) is directly testable here: attempt the violation, assert MySQL
 *    rejects it. This is the strongest possible proof a constraint works — not "the migration ran,"
 *    but "the database itself now refuses the bad state."
 *  - Constraint SKIP-ON-DIRTY-DATA (STEP 19) is NOT re-tested here — once a constraint is live, the
 *    dirty state it guards against can no longer even be inserted, so there's nothing left to skip.
 *    That behavior was verified manually against two disposable, pre-constraint databases (a clean
 *    apply and a seeded-dirty apply) — see docs/phase-5-final-report.md section 13 for the exact
 *    commands and output. The two checks this class deliberately CAN still exercise for real dirty
 *    data are the ones Phase 5 left deliberately unenforced (ellsms_contacts, ellsms_autoreply_log
 *    orphans) — seeded and asserted below.
 */
final class DatabaseIntegrityTest extends IntegrationTestCase
{
    private function makeBulkJob(int $userId): int {
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 't', '5000', 'pending', 0)")
            ->execute([$userId]);
        return (int)db()->lastInsertId();
    }

    // --- STEP 20: constraint regression — the DB itself must reject each violation ---

    public function testOrphanBulkItemIsRejectedByForeignKey(): void
    {
        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (999999, '0912', 'x', 'pending')")->execute();
    }

    public function testOrphanNumberCategoryItemIsRejectedByForeignKey(): void
    {
        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_number_category_items (category_id, mobile) VALUES (999999, '0912')")->execute();
    }

    public function testOrphanTicketReplyIsRejectedByForeignKey(): void
    {
        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_ticket_replies (ticket_id, user_id, is_admin_reply, body) VALUES (999999, 1, 0, 'x')")->execute();
    }

    public function testOrphanWalletTransactionIsRejectedByForeignKey(): void
    {
        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key) VALUES (999999, 'purchase', 10, 0, 10, 'test', 'x', 'test:orphan')")->execute();
    }

    public function testOrphanWalletReservationIsRejectedByForeignKey(): void
    {
        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_wallet_reservations (user_id, amount, remaining_amount, reference_type, reference_id, idempotency_key) VALUES (999999, 10, 10, 'test', 'x', 'test:orphan')")->execute();
    }

    // Phase 6 closure (db/migrations/2026_07_30_number_category_tenancy.sql) replaced the original
    // Phase 5 GLOBAL uniq_category_name UNIQUE(name) with a tenant-local uniq_org_category_name
    // UNIQUE(organization_id, name) — two organizations may now share a category name (see
    // TenantIsolationTest::testTwoOrganizationsCanShareTheSameCategoryName), but the SAME
    // organization still cannot. This test proves the still-live half of that constraint.
    public function testDuplicateCategoryNameWithinTheSameOrganizationIsRejectedByUniqueConstraint(): void
    {
        require_once __DIR__ . '/../../app/tenant.php';
        $orgResult = create_organization($this->makeUser(), 'Dup Category Org');
        $organizationId = (int)$orgResult['organization_id'];
        $name = 'dup-test-' . bin2hex(random_bytes(4));

        db()->prepare('INSERT INTO ellsms_number_categories (name, created_by, organization_id) VALUES (?, 1, ?)')->execute([$name, $organizationId]);

        $this->expectException(PDOException::class);
        db()->prepare('INSERT INTO ellsms_number_categories (name, created_by, organization_id) VALUES (?, 1, ?)')->execute([$name, $organizationId]);
    }

    public function testDuplicatePaymentAuthorityIsRejectedByUniqueConstraint(): void
    {
        $authority = 'AUTH-' . bin2hex(random_bytes(6));
        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (1, 100, 100000, ?, 'pending')")->execute([$authority]);

        $this->expectException(PDOException::class);
        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (1, 200, 200000, ?, 'pending')")->execute([$authority]);
    }

    public function testMultipleNullPaymentAuthoritiesAreAllowed(): void
    {
        // UNIQUE permits multiple NULLs in MySQL — a payment row created before ZarinPal responds
        // with an authority must not be blocked from existing alongside another such row.
        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (1, 100, 100000, NULL, 'pending')")->execute();
        db()->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (1, 100, 100000, NULL, 'pending')")->execute();
        $this->assertTrue(true); // reaching here without a PDOException is the assertion
    }

    public function testCategoryItemsCascadeDeleteWithParentCategory(): void
    {
        db()->prepare('INSERT INTO ellsms_number_categories (name, created_by) VALUES (?, 1)')->execute(['cascade-test-' . bin2hex(random_bytes(4))]);
        $categoryId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_number_category_items (category_id, mobile) VALUES (?, ?)')->execute([$categoryId, '09120000000']);

        db()->prepare('DELETE FROM ellsms_number_categories WHERE id = ?')->execute([$categoryId]);

        $st = db()->prepare('SELECT COUNT(*) c FROM ellsms_number_category_items WHERE category_id = ?');
        $st->execute([$categoryId]);
        $this->assertSame(0, (int)$st->fetch()['c'], 'ON DELETE CASCADE must remove dependent items automatically');
    }

    public function testBulkJobCannotBeDeletedWhileItemsExist(): void
    {
        $userId = $this->makeUser();
        $jobId = $this->makeBulkJob($userId);
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '0912', 'x', 'pending')")->execute([$jobId]);

        $this->expectException(PDOException::class);
        db()->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
    }

    // --- Deliberately-deferred checks: still detectable via db-integrity-check.php since no DB constraint blocks them yet ---

    public function testIntegrityCheckDetectsDeferredContactsDuplicate(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?, ?, ?, ?)')
            ->execute([$userId, 'A', '09120000000', 'g1']);
        db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?, ?, ?, ?)')
            ->execute([$userId, 'B', '09120000000', 'g1']);

        $st = db()->query("SELECT COUNT(*) c FROM (SELECT user_id, mobile, group_name FROM ellsms_contacts GROUP BY user_id, mobile, group_name HAVING COUNT(*) > 1) d");
        $this->assertGreaterThan(0, (int)$st->fetch()['c'], 'contacts uniqueness is deliberately unenforced — this proves the underlying condition db-integrity-check reports is real, not just a query typo');
    }

    public function testIntegrityCheckDetectsDeferredAutoreplyLogOrphan(): void
    {
        db()->prepare("INSERT INTO ellsms_autoreply_rules (user_id, originator, keyword, match_type, reply_content) VALUES (1, '5000', 'hi', 'exact', 'hello')")->execute();
        $ruleId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_autoreply_log (rule_id, inbound_message_id, sender, originator, reply_content, ok, info, status) VALUES (?, ?, '0912', '5000', 'hi', 1, '', 'sent')")
            ->execute([$ruleId, random_int(1000000, 9999999)]);
        db()->prepare('DELETE FROM ellsms_autoreply_rules WHERE id = ?')->execute([$ruleId]);

        $st = db()->prepare('SELECT COUNT(*) c FROM ellsms_autoreply_log l LEFT JOIN ellsms_autoreply_rules r ON r.id = l.rule_id WHERE r.id IS NULL AND l.rule_id = ?');
        $st->execute([$ruleId]);
        $this->assertSame(1, (int)$st->fetch()['c'], "confirms public/autoreply.php's rule delete does not clean up log rows — the pre-existing gap db-integrity-check surfaces as non-critical");
    }
}
