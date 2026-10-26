<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/LiveCostEstimate.php';

/**
 * The send composer's live cost (app/LiveCostEstimate.php, public/send-estimate.php): the pure
 * shaping of an estimate_message_cost() result into the JSON the composer renders. The estimate
 * itself is the cost preview's own, covered by CostEstimatorTest and tests/Integration/CostPreviewTest.php.
 */
final class LiveCostEstimateTest extends TestCase
{
    public function testASuccessfulEstimateKeepsTheFiguresThePreviewCardShows(): void
    {
        $payload = \live_cost_payload([
            'ok' => true,
            'recipients' => ['input_count' => 10, 'invalid_count' => 2, 'duplicate_count' => 1, 'blacklisted_count' => 1, 'eligible_count' => 6],
            'segments' => ['per_recipient' => 2, 'total' => 12],
            'pricing' => ['estimated_cost' => 9000, 'price_source' => 'route', 'groups' => [['provider' => 'secret']]],
            'wallet' => ['balance' => 5000, 'estimated_remaining' => -4000, 'sufficient' => false],
            'quota' => ['sufficient' => true],
        ]);

        $this->assertSame([
            'ok' => true,
            'recipients' => ['input' => 10, 'invalid' => 2, 'duplicate' => 1, 'blacklisted' => 1, 'eligible' => 6],
            'segments' => ['per_recipient' => 2, 'total' => 12],
            'cost' => 9000,
            'exempt' => false,
            'wallet' => ['balance' => 5000, 'remaining' => -4000, 'sufficient' => false],
            'quota_sufficient' => true,
        ], $payload, 'only the totals reach the browser — no pricing groups or route detail');
    }

    public function testAnAdminEstimateIsReportedAsExempt(): void
    {
        $payload = \live_cost_payload([
            'ok' => true,
            'recipients' => ['eligible_count' => 3],
            'segments' => ['per_recipient' => 1, 'total' => 3],
            'pricing' => ['estimated_cost' => 0, 'price_source' => 'admin_exempt'],
            'wallet' => ['balance' => 0, 'estimated_remaining' => 0, 'sufficient' => true],
        ]);
        $this->assertTrue($payload['exempt']);
        $this->assertSame(0, $payload['cost']);
    }

    public function testARefusalCarriesThePersianReasonAndTheRecipientCounts(): void
    {
        $payload = \live_cost_payload([
            'ok' => false,
            'reason' => 'no_eligible_recipients',
            'recipients' => ['input_count' => 2, 'invalid_count' => 2, 'eligible_count' => 0],
        ]);
        $this->assertFalse($payload['ok']);
        $this->assertSame('no_eligible_recipients', $payload['reason']);
        $this->assertSame(\cost_preview_reason_message('no_eligible_recipients'), $payload['message']);
        $this->assertSame(2, $payload['recipients']['invalid']);
    }

    public function testAPricingGapReportsHowManyRecipientsAreUnpriced(): void
    {
        $payload = \live_cost_payload([
            'ok' => false,
            'reason' => 'pricing_unavailable',
            'recipients' => ['input_count' => 5, 'eligible_count' => 5],
            'pricing_failure' => ['priced_count' => 3, 'unpriced_count' => 2, 'reasons' => ['route_price_missing' => 2]],
        ]);
        $this->assertSame(2, $payload['unpriced']);
        $this->assertArrayNotHasKey('pricing_failure', $payload);
    }

    public function testASenderRefusalHasNoRecipientBlock(): void
    {
        $payload = \live_cost_payload(['ok' => false, 'reason' => 'sender_not_allowed']);
        $this->assertSame(['ok' => false, 'reason' => 'sender_not_allowed', 'message' => \cost_preview_reason_message('sender_not_allowed')], $payload);
    }

    public function testTheEndpointIsGatedLikeTheSendPages(): void
    {
        $this->assertContains('new_send', \menu_keys_for_script('/var/www/public/send-estimate.php'));
        $this->assertContains('send', \menu_keys_for_script('/var/www/public/send-estimate.php'));
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/send-estimate.php');
        $this->assertStringContainsString('csrf_check()', $source);
        $this->assertStringContainsString('Permissions::MESSAGES_SEND', $source);
        $this->assertStringNotContainsString('cost_preview_record', preg_replace('#/\*.*?\*/#s', '', $source), 'live estimates are not logged as previews');
    }
}
