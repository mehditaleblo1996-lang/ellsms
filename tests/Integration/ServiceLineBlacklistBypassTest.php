<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Service lines (docs/service-lines.md): a line with ellsms_numbers.bypass_blacklist = 1 skips the
 * customer's do-not-contact list — like Vesal's Number.byPassBlackList — while ordinary lines are
 * filtered exactly as before. Covers the shared filter, the cost preview, and the large-import
 * pipeline, all against real MySQL.
 */
final class ServiceLineBlacklistBypassTest extends IntegrationTestCase
{
    private string $serviceLine = '5000900801';
    private string $normalLine = '5000900802';
    private string $blocked = '989121280009';
    private int $userId;
    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser(['originator' => $this->serviceLine]);
        $result = create_organization($this->userId, 'Service Line Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 10000 WHERE user_id = ?')->execute([$this->userId]);

        $ins = db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id, bypass_blacklist) VALUES (?,?,?)');
        $ins->execute([$this->serviceLine, $this->userId, 1]);
        $ins->execute([$this->normalLine, $this->userId, 0]);
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?,?)')->execute([$this->userId, $this->blocked]);
    }

    private function actor(): array
    {
        return ['id' => $this->userId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => $this->serviceLine];
    }

    public function testNewNumbersAreNotServiceLinesByDefault(): void
    {
        db()->prepare('INSERT INTO ellsms_numbers (number) VALUES (?)')->execute(['5000900899']);
        $this->assertFalse(originator_bypasses_blacklist('5000900899'));
    }

    public function testAServiceLineSkipsTheBlacklistAndANormalLineDoesNot(): void
    {
        $dests = [$this->blocked, '989121280001'];

        $this->assertSame([$dests, 0], filter_blacklist($this->userId, $dests, $this->serviceLine));
        $this->assertSame([['989121280001'], 1], filter_blacklist($this->userId, $dests, $this->normalLine));
        // No line given, or a line that is not in ellsms_numbers at all: filtered, as before.
        $this->assertSame([['989121280001'], 1], filter_blacklist($this->userId, $dests));
        $this->assertSame([['989121280001'], 1], filter_blacklist($this->userId, $dests, '5000900877'));
    }

    public function testTheLineIsMatchedAfterNormalization(): void
    {
        $this->assertTrue(originator_bypasses_blacklist(' ' . $this->serviceLine . ' '));
        $this->assertSame([$this->serviceLine => true], blacklist_bypass_lines([$this->serviceLine, $this->normalLine, '', 'abc']));
    }

    public function testTurningTheFlagOffTakesEffectImmediately(): void
    {
        $this->assertSame(0, filter_blacklist($this->userId, [$this->blocked], $this->serviceLine)[1]);
        db()->prepare('UPDATE ellsms_numbers SET bypass_blacklist = 0 WHERE number = ?')->execute([$this->serviceLine]);
        $this->assertSame(1, filter_blacklist($this->userId, [$this->blocked], $this->serviceLine)[1], 'no stale cache');
    }

    public function testTheCostPreviewAgreesWithTheSend(): void
    {
        $service = estimate_message_cost($this->actor(), $this->serviceLine, [$this->blocked, '989121280001'], 'test');
        $this->assertTrue($service['ok']);
        $this->assertSame(0, $service['recipients']['blacklisted_count']);
        $this->assertSame(2, $service['recipients']['eligible_count']);

        $normal = estimate_message_cost($this->actor(), $this->normalLine, [$this->blocked, '989121280001'], 'test');
        $this->assertTrue($normal['ok']);
        $this->assertSame(1, $normal['recipients']['blacklisted_count']);
        $this->assertSame(1, $normal['recipients']['eligible_count']);

        $bulk = estimate_bulk_cost($this->actor(), $this->serviceLine, [
            ['mobile' => $this->blocked, 'content' => 'a'],
            ['mobile' => '989121280001', 'content' => 'b'],
        ]);
        $this->assertTrue($bulk['ok']);
        $this->assertSame(0, $bulk['recipients']['blacklisted_count']);
    }

    public function testOnlyTheBlacklistIsSkippedNotLineOptOuts(): void
    {
        db()->prepare("INSERT INTO ellsms_line_optouts (originator, mobile, source) VALUES (?,?,'sms')")->execute([$this->serviceLine, '989121280002']);
        // The blacklist filter lets the opted-out number through; the send-time opt-out check still removes it.
        $this->assertSame(0, filter_blacklist($this->userId, ['989121280002'], $this->serviceLine)[1]);
        $this->assertSame(['989121280002' => true], line_optout_filter($this->serviceLine, ['989121280002']));
    }
}
