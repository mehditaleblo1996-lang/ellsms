<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * REPORTING STATUS + SEND MODAL, Part B — the shared send-confirmation partials
 * (app/views/cost_preview.php, app/views/cost_preview_unpriced.php) rendered directly with a
 * synthetic estimate, the same shape estimate_message_cost() returns (see CostEstimatorTest for
 * the real function's output). No HTTP server and no database: these partials read only
 * $costPreview / $costPricingFailure / $previewFormFields and never call db().
 *
 * What this proves: the confirmation stays an inline overlay on the same render (not a second
 * page), it carries CSRF, it has exactly one path to an actual send (one confirm button, one
 * form), and the "cancel" affordance never doubles as a network request.
 */
final class SendConfirmationModalTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['csrf']);
    }

    /* ================= priced preview → the confirmation modal ================= */

    public function testThePricedPreviewRendersAsAnAutoOpenOverlayOnTheSamePage(): void
    {
        $html = $this->renderCostPreview();

        // An overlay that starts open — no redirect, no second page, just markup already present
        // in this same response.
        $this->assertStringContainsString('modal-overlay is-open', $html);
        $this->assertStringContainsString('id="sendConfirmOverlay"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
    }

    public function testTheModalShowsSenderRecipientPartsAndCost(): void
    {
        $html = $this->renderCostPreview();

        $this->assertStringContainsString('5000', $html, 'sender/originator must be shown');
        $this->assertStringContainsString('فرستنده', $html);
        $this->assertStringContainsString('گیرندگان', $html);
        $this->assertStringContainsString('تعداد پیامک برای هر گیرنده', $html);
        $this->assertStringContainsString('هزینه‌ی تقریبی', $html);
    }

    public function testTheModalShowsImmediateSendWhenNoScheduleWasRequested(): void
    {
        $html = $this->renderCostPreview(['mode' => 'now']);
        $this->assertStringContainsString('ارسال فوری', $html);
    }

    public function testTheModalShowsTheScheduledTimeWhenASendWasScheduled(): void
    {
        $_POST['mode'] = 'later';
        $_POST['send_date_y'] = '1405';
        $_POST['send_date_m'] = '6';
        $_POST['send_date_d'] = '1';
        $_POST['send_time_h'] = '10';
        $_POST['send_time_i'] = '30';
        try {
            $html = $this->renderCostPreview([], setPostMode: false);
        } finally {
            unset($_POST['mode'], $_POST['send_date_y'], $_POST['send_date_m'], $_POST['send_date_d'], $_POST['send_time_h'], $_POST['send_time_i']);
        }
        $this->assertStringContainsString('زمان‌بندی‌شده', $html);
    }

    public function testTheConfirmFormCarriesCsrfAndTheOriginalInputsForward(): void
    {
        $html = $this->renderCostPreview();

        $this->assertStringContainsString('name="_csrf"', $html, 'the confirm submit must still carry CSRF — send.php\'s csrf_check() is untouched');
        $this->assertStringContainsString('name="content" value="سلام"', $html, 'the original submission must be resubmitted, not re-typed');
        $this->assertStringContainsString('name="previewed_cost"', $html, 'the staleness re-check the confirm branch performs is unaffected by the modal wrapper');
    }

    public function testThereIsExactlyOneWayToActuallySend(): void
    {
        $html = $this->renderCostPreview();

        // Exactly one <form>, exactly one submit button named do=confirm — nothing in the modal
        // markup gives JavaScript a second path to trigger a send.
        $this->assertSame(1, substr_count($html, '<form'), 'a second form would be a second, uncontrolled path to a send');
        $this->assertSame(1, substr_count($html, 'value="confirm"'));
        $this->assertStringContainsString('id="sendConfirmSubmit"', $html);
    }

    public function testCancelIsAPlainButtonNeverASubmitOrANavigation(): void
    {
        $html = $this->renderCostPreview();

        $this->assertStringContainsString('id="sendConfirmCancel"', $html);
        // The old flow reloaded the page via an empty-href anchor; the modal version must not
        // navigate anywhere, and must not be a second submit button that could fire the form.
        $this->assertStringNotContainsString('<a class="btn" href="">', $html);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*id="sendConfirmCancel"/', $html,
            'cancel must be an inert button — a submit button here would double as a second way to POST');
    }

    public function testTheOverlayWiresEscapeAndBackdropCloseWithoutOpeningASecondRequest(): void
    {
        $html = $this->renderCostPreview();

        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('sendConfirmOverlay', $html);
        // Closing only ever toggles a class — it must never call submit()/fetch()/location assignment.
        $this->assertStringNotContainsString('.submit()', $html);
        $this->assertStringNotContainsString('fetch(', $html);
        $this->assertStringNotContainsString('location.href', $html);
    }

    public function testTheSubmitHandlerDisablesTheButtonToPreventADoubleSend(): void
    {
        $html = $this->renderCostPreview();

        $this->assertStringContainsString("addEventListener('submit'", $html);
        $this->assertStringContainsString('submit.disabled = true', $html);
    }

    public function testAnInsufficientWalletDisablesTheConfirmButtonRatherThanHidingTheRisk(): void
    {
        $html = $this->renderCostPreview(['wallet' => ['balance' => 0, 'estimated_remaining' => -6, 'sufficient' => false]]);

        $this->assertStringNotContainsString('value="confirm"', $html, 'an insufficient wallet must not offer a confirm button at all');
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('اعتبار کافی نیست', $html);
    }

    /* ================= unpriced failure → dismiss-only modal, no confirm path ================= */

    public function testAnUnpricedFailureRendersAsAModalWithNoConfirmButton(): void
    {
        $html = $this->renderUnpriced();

        $this->assertStringContainsString('modal-overlay is-open', $html);
        $this->assertStringContainsString('id="sendUnpricedOverlay"', $html);
        // No possible way to send here — genuinely unknown pricing must never be confirmable.
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('value="confirm"', $html);
        $this->assertStringContainsString('id="sendUnpricedClose"', $html);
    }

    /* ================= fixtures ================= */

    /** @param array<string,mixed> $overrides deep-merged into the synthetic estimate */
    private function renderCostPreview(array $overrides = [], bool $setPostMode = true): string
    {
        if ($setPostMode) {
            $_POST['mode'] = 'now';
        }
        $costPreview = array_replace_recursive($this->syntheticEstimate(), $overrides);
        $previewFormFields = '<input type="hidden" name="content" value="سلام">'
            . '<input type="hidden" name="destinations" value="989121234567">';

        return $this->renderPartial(__DIR__ . '/../../app/views/cost_preview.php', [
            'costPreview' => $costPreview,
            'previewFormFields' => $previewFormFields,
        ]);
    }

    private function renderUnpriced(): string
    {
        $costPricingFailure = [
            'pricing_failure' => ['priced_count' => 2, 'unpriced_count' => 1, 'reasons' => ['no_route' => 1]],
            'recipients' => ['input_count' => 3, 'eligible_count' => 3],
        ];
        return $this->renderPartial(__DIR__ . '/../../app/views/cost_preview_unpriced.php', [
            'costPricingFailure' => $costPricingFailure,
        ]);
    }

    /** @param array<string,mixed> $vars extracted into the partial's local scope */
    private function renderPartial(string $path, array $vars): string
    {
        $render = static function (string $__path, array $__vars): string {
            extract($__vars);
            ob_start();
            require $__path;
            return (string)ob_get_clean();
        };
        return $render($path, $vars);
    }

    /** @return array<string,mixed> shaped exactly like estimate_message_cost()'s ok=true return */
    private function syntheticEstimate(): array
    {
        return [
            'ok'         => true,
            'kind'       => 'message',
            'originator' => '5000',
            'recipients' => [
                'input_count' => 2, 'invalid_count' => 0, 'duplicate_count' => 0,
                'blacklisted_count' => 0, 'empty_content_count' => 0, 'eligible_count' => 2,
            ],
            'message' => [
                'encoding' => 'gsm7', 'characters' => 5, 'segments' => 1, 'concatenated' => false,
                'single_segment_limit' => 160, 'concatenated_segment_limit' => 153,
                'characters_remaining_in_segment' => 155,
            ],
            'segments' => [
                'per_recipient' => 1, 'total' => 2, 'distribution' => ['1' => 2], 'exact' => true,
            ],
            'pricing' => [
                'unit' => 'credit_per_segment', 'currency' => 'credit',
                'credits_per_segment' => 1.0, 'unit_price_millicredits' => 1000,
                'unit_price_min_millicredits' => 1000, 'unit_price_max_millicredits' => 1000,
                'price_source' => 'route_operator', 'message_type' => 'promotional',
                'legacy_fallback_used' => false,
                'groups' => [[
                    'operator' => 'mci', 'operator_name' => 'MCI', 'provider' => 'p1', 'route' => 'default',
                    'message_type' => 'promotional', 'recipients' => 2, 'segments' => 2,
                    'unit_price' => 1.0, 'unit_price_millicredits' => 1000, 'price_source' => 'route_operator', 'cost' => 2,
                ]],
                'rial_per_credit' => 0, 'rial_currency' => 'IRR',
                'estimator_version' => 1, 'priced_at' => '2026-08-18 10:00:00',
                'estimated_cost' => 2,
            ],
            'wallet' => ['balance' => 1000, 'reserved' => 0, 'estimated_cost' => 2, 'estimated_remaining' => 998, 'sufficient' => true],
            'quota'  => ['enforced' => false, 'estimated_usage' => 2, 'sufficient' => true],
            'notes'  => ['estimate_only' => true, 'revalidated_at_send' => true],
        ];
    }
}
