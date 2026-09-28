<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #41 — reminders before a subscription / trial / grace period ends: the most urgent due one only,
 * once per period, re-armed by a renewal, skipped when the organization chose to cancel.
 */
final class SubscriptionRemindersTest extends IntegrationTestCase
{
    private array $sms = [];
    private array $emails = [];
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('BILLING_ENABLED=1');
        putenv('SUBSCRIPTION_REMINDER_DAYS=7,3,1');
        db()->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
                       VALUES (?, 'طرح طلایی', 'active', 0, 1, 'monthly', 1000, 'IRR', 0)")->execute(['gold_' . bin2hex(random_bytes(3))]);
        $this->planId = (int)db()->lastInsertId();
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        putenv('SUBSCRIPTION_REMINDER_DAYS');
        parent::tearDown();
    }

    private function now(): int
    {
        return (int)db()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    }

    private function runReminders(?int $now = null): array
    {
        return subscription_reminders_run(
            false,
            function (string $mobile, string $text): bool { $this->sms[] = [$mobile, $text]; return true; },
            function (string $email, string $title, string $body): bool { $this->emails[] = [$email, $body]; return true; },
            $now
        );
    }

    /** An organization whose effective subscription's relevant end is $secondsFromNow away. */
    private function makeSubscription(string $status, int $secondsFromNow, bool $cancelAtPeriodEnd = false): array
    {
        $ownerId = $this->makeUser();
        $mobile = '98912' . random_int(1000000, 9999999);
        db()->prepare("UPDATE user_ SET firstname = 'Ali', lastname = 'Karimi', mobile = ?, email = ? WHERE id = ?")
            ->execute([$mobile, "sub{$ownerId}@example.test", $ownerId]);
        $orgId = (int)create_organization($ownerId, 'Reminder Org ' . bin2hex(random_bytes(3)))['organization_id'];
        $end = $this->now() + $secondsFromNow;
        $column = ['active' => 'current_period_end', 'trialing' => 'trial_ends_at', 'grace' => 'grace_ends_at'][$status];
        db()->prepare("INSERT INTO ellsms_subscriptions (organization_id, plan_id, status, effective_organization_id, cancel_at_period_end, {$column})
                       VALUES (?,?,?,?,?, FROM_UNIXTIME(?))")
            ->execute([$orgId, $this->planId, $status, $orgId, $cancelAtPeriodEnd ? 1 : 0, $end]);
        return ['org' => $orgId, 'sub' => (int)db()->lastInsertId(), 'mobile' => $mobile, 'email' => "sub{$ownerId}@example.test", 'end' => $end, 'owner' => $ownerId];
    }

    private function smsTo(string $mobile): array
    {
        return array_values(array_filter($this->sms, fn($m) => $m[0] === $mobile));
    }

    public function testTheDueOffsetIsTheTightestWindowWeAreIn(): void
    {
        $offsets = [7, 3, 1];
        $this->assertSame(7, subscription_reminder_due_offset(5 * 86400, $offsets));
        $this->assertSame(3, subscription_reminder_due_offset((int)(2.5 * 86400), $offsets));
        $this->assertSame(1, subscription_reminder_due_offset(3600, $offsets));
        $this->assertNull(subscription_reminder_due_offset(10 * 86400, $offsets));
        $this->assertNull(subscription_reminder_due_offset(-10, $offsets));
    }

    public function testAnActiveSubscriptionEndingSoonGetsOneReminderOnce(): void
    {
        $s = $this->makeSubscription('active', 2 * 86400);
        $this->runReminders();
        $this->runReminders();

        $sms = $this->smsTo($s['mobile']);
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('Ali Karimi', $sms[0][1]);
        $this->assertStringContainsString('طرح طلایی', $sms[0][1]);
        $this->assertStringContainsString('اشتراک', $sms[0][1]);
        $this->assertCount(1, array_filter($this->emails, fn($e) => $e[0] === $s['email']));
        $row = db()->query('SELECT offset_days, sms_sent, email_sent FROM ellsms_subscription_reminders WHERE subscription_id = ' . $s['sub'])->fetch();
        $this->assertSame([3, 1, 1], [(int)$row['offset_days'], (int)$row['sms_sent'], (int)$row['email_sent']]);
        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_notifications WHERE user_id = ? AND event_key = 'subscription.expiring'");
        $st->execute([$s['owner']]);
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    public function testAfterADownWorkerOnlyTheMostUrgentReminderIsSent(): void
    {
        $s = $this->makeSubscription('active', 20 * 3600);
        $this->runReminders();
        $this->assertCount(1, $this->smsTo($s['mobile']), 'not the 7-, 3- and 1-day reminders at once');
        $this->assertSame(1, (int)db()->query('SELECT offset_days FROM ellsms_subscription_reminders WHERE subscription_id = ' . $s['sub'])->fetchColumn());
    }

    public function testEachWindowSendsItsOwnReminderAsTheEndApproaches(): void
    {
        $s = $this->makeSubscription('active', 6 * 86400);
        $this->runReminders($s['end'] - 6 * 86400);   // 7-day window
        $this->runReminders($s['end'] - 2 * 86400);   // 3-day window
        $this->runReminders($s['end'] - 12 * 3600);   // 1-day window
        $this->runReminders($s['end'] - 6 * 3600);    // still the 1-day window
        $this->assertCount(3, $this->smsTo($s['mobile']));
    }

    public function testARenewalReArmsTheReminders(): void
    {
        $s = $this->makeSubscription('active', 2 * 86400);
        $this->runReminders();
        $newEnd = $s['end'] + 30 * 86400;
        db()->prepare('UPDATE ellsms_subscriptions SET current_period_end = FROM_UNIXTIME(?) WHERE id = ?')->execute([$newEnd, $s['sub']]);
        $this->runReminders($newEnd - 2 * 86400);
        $this->assertCount(2, $this->smsTo($s['mobile']));
    }

    public function testASubscriptionSetToCancelGetsNoRenewalReminder(): void
    {
        $s = $this->makeSubscription('active', 2 * 86400, true);
        $this->runReminders();
        $this->assertSame([], $this->smsTo($s['mobile']));
    }

    public function testATrialAndAGracePeriodAreRemindedWithTheirOwnWording(): void
    {
        $trial = $this->makeSubscription('trialing', 86400);
        $grace = $this->makeSubscription('grace', 86400);
        $this->runReminders();
        $this->assertStringContainsString('دوره‌ی آزمایشی', $this->smsTo($trial['mobile'])[0][1]);
        $this->assertStringContainsString('مهلت پرداخت', $this->smsTo($grace['mobile'])[0][1]);
    }

    public function testNothingHappensWhenBillingIsOff(): void
    {
        putenv('BILLING_ENABLED=0');
        $s = $this->makeSubscription('active', 86400);
        $this->assertSame(0, $this->runReminders()['sent']);
        $this->assertSame([], $this->smsTo($s['mobile']));
    }

    public function testDryRunNeitherSendsNorClaims(): void
    {
        $s = $this->makeSubscription('active', 86400);
        $summary = subscription_reminders_run(true, fn() => true, fn() => true);
        $this->assertContains($s['org'], $summary['would_send']);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_subscription_reminders WHERE subscription_id = ' . $s['sub'])->fetchColumn());
    }
}
