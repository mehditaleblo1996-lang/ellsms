<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #35 — low-credit alerts are sent (app/LowCreditAlerts.php): once per period, capped, re-armed by a
 * top-up, only through the channels the organization chose, and never twice for the same claim.
 */
final class LowCreditAlertsTest extends IntegrationTestCase
{
    private array $sms = [];
    private array $emails = [];

    private function runAlerts(bool $dryRun = false): array
    {
        return low_credit_alerts_run(
            $dryRun,
            function (string $mobile, string $text): bool { $this->sms[] = [$mobile, $text]; return true; },
            function (string $email, string $title, string $body): bool { $this->emails[] = [$email, $body]; return true; }
        );
    }

    /** An organization whose owner has a mobile/email, with the given balance and preferences. */
    private function makeOrganization(int $balance, array $prefs = []): array
    {
        $ownerId = $this->makeUser();
        $mobile = '98912' . random_int(1000000, 9999999);
        db()->prepare("UPDATE user_ SET firstname = 'Sara', lastname = 'Ahmadi', mobile = ?, email = ? WHERE id = ?")
            ->execute([$mobile, "owner{$ownerId}@example.test", $ownerId]);
        $org = create_organization($ownerId, 'LowCredit Org ' . bin2hex(random_bytes(3)));
        $orgId = (int)$org['organization_id'];
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ? WHERE user_id = ?')->execute([$balance, $ownerId]);

        $prefs = array_merge([
            'low_credit_alert_enabled' => 1, 'low_credit_threshold' => 100,
            'sms_alert_enabled' => 1, 'email_alert_enabled' => 1, 'alert_mobile' => '', 'alert_email' => '',
        ], $prefs);
        db()->prepare(
            'INSERT INTO ellsms_organization_notification_preferences
               (organization_id, low_credit_alert_enabled, low_credit_threshold, email_alert_enabled, sms_alert_enabled, alert_email, alert_mobile)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$orgId, $prefs['low_credit_alert_enabled'], $prefs['low_credit_threshold'], $prefs['email_alert_enabled'],
                    $prefs['sms_alert_enabled'], $prefs['alert_email'], $prefs['alert_mobile']]);

        return ['org' => $orgId, 'owner' => $ownerId, 'mobile' => $mobile, 'email' => "owner{$ownerId}@example.test"];
    }

    private function smsTo(string $mobile): array
    {
        return array_values(array_filter($this->sms, fn($m) => $m[0] === $mobile));
    }

    private function emailsTo(string $email): array
    {
        return array_values(array_filter($this->emails, fn($m) => $m[0] === $email));
    }

    private function agePreviousAlert(int $orgId, int $hours): void
    {
        db()->prepare('UPDATE ellsms_organization_notification_preferences
                       SET last_low_credit_alert_at = CURRENT_TIMESTAMP - INTERVAL ? HOUR WHERE organization_id = ?')
            ->execute([$hours, $orgId]);
    }

    public function testBelowThresholdSendsOneSmsAndEmailToTheOwnerAndAPanelNotice(): void
    {
        $o = $this->makeOrganization(50);
        $this->runAlerts();

        $this->assertCount(1, $this->smsTo($o['mobile']));
        $this->assertStringContainsString('Sara Ahmadi', $this->smsTo($o['mobile'])[0][1]);
        $this->assertStringContainsString('۵۰', $this->smsTo($o['mobile'])[0][1]);
        $this->assertCount(1, $this->emailsTo($o['email']));

        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_notifications WHERE user_id = ? AND event_key = 'credit.low'");
        $st->execute([$o['owner']]);
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    public function testASecondPassInTheSamePeriodSendsNothing(): void
    {
        $o = $this->makeOrganization(50);
        $this->runAlerts();
        $this->runAlerts();
        $this->assertCount(1, $this->smsTo($o['mobile']));
    }

    public function testAfterThePeriodItAlertsAgainButStopsAtTheCap(): void
    {
        putenv('LOW_CREDIT_ALERT_MAX_COUNT=3');
        $o = $this->makeOrganization(50);
        for ($i = 0; $i < 5; $i++) {
            $this->runAlerts();
            $this->agePreviousAlert($o['org'], 25);
        }
        putenv('LOW_CREDIT_ALERT_MAX_COUNT');
        $this->assertCount(3, $this->smsTo($o['mobile']));
    }

    public function testATopUpReArmsTheAlert(): void
    {
        $o = $this->makeOrganization(50);
        $this->runAlerts();
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 500 WHERE user_id = ?')->execute([$o['owner']]);
        $summary = $this->runAlerts();
        $this->assertGreaterThanOrEqual(1, $summary['reset']);

        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 10 WHERE user_id = ?')->execute([$o['owner']]);
        $this->runAlerts();
        $this->assertCount(2, $this->smsTo($o['mobile']), 'a drop after a top-up must alert again at once');
    }

    public function testNothingIsSentAboveTheThresholdOrWhenDisabled(): void
    {
        $above = $this->makeOrganization(150);
        $disabled = $this->makeOrganization(10, ['low_credit_alert_enabled' => 0]);
        $this->runAlerts();
        $this->assertSame([], $this->smsTo($above['mobile']));
        $this->assertSame([], $this->smsTo($disabled['mobile']));
    }

    public function testOnlyTheChosenChannelsAndRecipientsAreUsed(): void
    {
        $o = $this->makeOrganization(10, ['sms_alert_enabled' => 1, 'email_alert_enabled' => 0, 'alert_mobile' => '989350000001']);
        $this->runAlerts();
        $this->assertCount(1, $this->smsTo('989350000001'), 'alert_mobile overrides the owner mobile');
        $this->assertSame([], $this->smsTo($o['mobile']));
        $this->assertSame([], $this->emailsTo($o['email']), 'email is off for this organization');
    }

    public function testTheClaimIsTakenOnlyOnce(): void
    {
        $o = $this->makeOrganization(10);
        $this->assertTrue(low_credit_alert_claim(db(), $o['org']));
        $this->assertFalse(low_credit_alert_claim(db(), $o['org']), 'a concurrent second claim must lose');
    }

    public function testDryRunListsButNeitherSendsNorClaims(): void
    {
        $o = $this->makeOrganization(10);
        $summary = $this->runAlerts(true);
        $this->assertContains($o['org'], $summary['would_send']);
        $this->assertSame([], $this->smsTo($o['mobile']));
        $this->assertTrue(low_credit_alert_claim(db(), $o['org']), 'dry run must not consume the claim');
    }
}
