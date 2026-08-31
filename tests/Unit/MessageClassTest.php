<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app/MessageClass.php — the canonical six-class priority order for issue #3
 * (OTP > Transactional > Notification > Scheduled > Bulk Campaign > Advertising).
 */
final class MessageClassTest extends TestCase
{
    public function testPriorityOrderMatchesAgreedClasses(): void
    {
        $this->assertSame(
            [
                MESSAGE_CLASS_OTP,
                MESSAGE_CLASS_TRANSACTIONAL,
                MESSAGE_CLASS_NOTIFICATION,
                MESSAGE_CLASS_SCHEDULED,
                MESSAGE_CLASS_BULK_CAMPAIGN,
                MESSAGE_CLASS_ADVERTISING,
            ],
            message_classes_by_priority()
        );
    }

    public function testRankIsStrictlyIncreasingInPriorityOrder(): void
    {
        $ranks = array_map('message_class_rank', message_classes_by_priority());
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'ranks must already be in ascending order');
        $this->assertSame(array_unique($ranks), $ranks, 'ranks must be unique');
    }

    public function testUnknownClassRanksLast(): void
    {
        $worstKnownRank = message_class_rank(MESSAGE_CLASS_ADVERTISING);
        $this->assertGreaterThan($worstKnownRank, message_class_rank('made_up_class'));
    }

    public function testNormalizeAcceptsKnownClasses(): void
    {
        foreach (message_classes_by_priority() as $class) {
            $this->assertSame($class, normalize_message_class($class));
        }
    }

    public function testNormalizeFallsBackToBulkCampaignForUnknownOrNull(): void
    {
        $this->assertSame(MESSAGE_CLASS_BULK_CAMPAIGN, normalize_message_class(null));
        $this->assertSame(MESSAGE_CLASS_BULK_CAMPAIGN, normalize_message_class('nonsense'));
    }

    #[DataProvider('pricingTypeMappings')]
    public function testMessageClassFromPricingType(?string $pricingType, string $expectedClass): void
    {
        $this->assertSame($expectedClass, message_class_from_pricing_type($pricingType));
    }

    public static function pricingTypeMappings(): array
    {
        return [
            'otp'         => ['otp', MESSAGE_CLASS_OTP],
            'transactional' => ['transactional', MESSAGE_CLASS_TRANSACTIONAL],
            'promotional' => ['promotional', MESSAGE_CLASS_ADVERTISING],
            'default'     => ['default', MESSAGE_CLASS_NOTIFICATION],
            'null'        => [null, MESSAGE_CLASS_NOTIFICATION],
            'unknown'     => ['whatever', MESSAGE_CLASS_NOTIFICATION],
        ];
    }

    public function testNormalizeBulkMessageClassRestrictsToTheTwoQueuedClasses(): void
    {
        $this->assertSame(MESSAGE_CLASS_BULK_CAMPAIGN, normalize_bulk_message_class(null));
        $this->assertSame(MESSAGE_CLASS_BULK_CAMPAIGN, normalize_bulk_message_class(MESSAGE_CLASS_OTP));
        $this->assertSame(MESSAGE_CLASS_ADVERTISING, normalize_bulk_message_class(MESSAGE_CLASS_ADVERTISING));
    }

    public function testSortMessageClassesOrdersHighestPriorityFirstRegardlessOfInputOrder(): void
    {
        $shuffled = [MESSAGE_CLASS_ADVERTISING, MESSAGE_CLASS_OTP, MESSAGE_CLASS_SCHEDULED];
        $this->assertSame(
            [MESSAGE_CLASS_OTP, MESSAGE_CLASS_SCHEDULED, MESSAGE_CLASS_ADVERTISING],
            sort_message_classes($shuffled)
        );
    }
}
