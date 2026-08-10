<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 9, STEP 20/30: guards run_due_schedules()'s due-row lookup against silently regressing
 * back to the non-sargable form. Before this phase it used
 * `COALESCE(next_attempt_at, run_at) <= NOW()`, which MySQL cannot use `idx_due` for — confirmed
 * via EXPLAIN against 20,000 seeded rows in a real (non-transaction-wrapped) session: `type: ALL`,
 * no key, 20000 rows examined, versus `type: range`, `key: idx_due`, 2002 rows examined for the
 * rewritten form (full before/after output in docs/observability-and-performance.md §14).
 *
 * This suite deliberately does NOT re-run EXPLAIN inside a test here: every IntegrationTestCase
 * test runs inside its own uncommitted transaction (rolled back in tearDown), and InnoDB's
 * cost-based optimizer relies on PERSISTED table statistics that do not reflect an open
 * transaction's own uncommitted inserts — so a plan-shape assertion here would be measuring stale
 * statistics, not the actual behavior, and forcing a refresh (`ANALYZE TABLE`) would itself commit
 * the transaction out from under this suite's isolation. Two things ARE safe to assert
 * deterministically instead: the condition is still expressed in the sargable form (not
 * re-wrapped in COALESCE by some future edit), and it still selects exactly the same rows the old
 * form did.
 */
final class QueryPlanRegressionTest extends IntegrationTestCase
{
    public function testDueConditionSqlNeverReintroducesCoalesce(): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            'COALESCE',
            schedule_due_condition_sql(),
            'reintroducing COALESCE(next_attempt_at, run_at) would silently make the due-row lookup non-sargable again — see this file\'s class docblock'
        );
    }

    public function testScheduleDueConditionSqlIsEquivalentToTheOldCoalesceForm(): void
    {
        // Regression pin: the rewrite must select exactly the same rows the old COALESCE()
        // expression would have, for both the "no retry pending" and "retry pending" shapes.
        $userId = $this->makeUser();
        $db = db();

        $dueByRunAt = $this->insertSchedule($userId, 'active', null, '-1 MINUTE');
        $dueByNextAttempt = $this->insertSchedule($userId, 'active', '-1 MINUTE', '1 HOUR');
        $notDueFutureRunAt = $this->insertSchedule($userId, 'active', null, '1 HOUR');
        $notDueFutureNextAttempt = $this->insertSchedule($userId, 'active', '1 HOUR', '-1 MINUTE');

        $oldForm = $db->query(
            "SELECT id FROM ellsms_schedule WHERE status = 'active' AND COALESCE(next_attempt_at, run_at) <= NOW()
             AND id IN ({$dueByRunAt},{$dueByNextAttempt},{$notDueFutureRunAt},{$notDueFutureNextAttempt})"
        )->fetchAll(\PDO::FETCH_COLUMN);
        sort($oldForm);

        $newForm = $db->query(
            'SELECT id FROM ellsms_schedule WHERE (' . schedule_due_condition_sql() . ")
             AND id IN ({$dueByRunAt},{$dueByNextAttempt},{$notDueFutureRunAt},{$notDueFutureNextAttempt})"
        )->fetchAll(\PDO::FETCH_COLUMN);
        sort($newForm);

        $expected = [$dueByRunAt, $dueByNextAttempt];
        sort($expected);
        $this->assertSame($expected, $oldForm, 'sanity check on the old form itself');
        $this->assertSame($oldForm, $newForm, 'the rewritten condition must select exactly the same rows as the original COALESCE() form');
    }

    private function insertSchedule(int $userId, string $status, ?string $nextAttemptOffset, string $runAtOffset): int {
        $nextAttempt = $nextAttemptOffset !== null ? "DATE_ADD(NOW(), INTERVAL {$nextAttemptOffset})" : 'NULL';
        db()->prepare(
            "INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, repeat_type, status, next_attempt_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL {$runAtOffset}), 'none', ?, {$nextAttempt})"
        )->execute([$userId, self::DEFAULT_ORIGINATOR, json_encode(['09120000000']), 'plan probe', $status]);
        return (int)db()->lastInsertId();
    }
}
