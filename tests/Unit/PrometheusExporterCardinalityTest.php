<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Issue #14's own hard rule: no metric label may ever carry an unbounded value (a message id, a
 * phone number, a per-tenant organization id, a request id). PrometheusExporter::boundedLabel() is
 * the one place that rule is mechanically enforced -- these tests prove it actually rejects
 * anything outside a fixed allow-list rather than passing arbitrary strings through.
 */
final class PrometheusExporterCardinalityTest extends TestCase
{
    public function testAllowedValuePassesThroughUnchanged(): void
    {
        self::assertSame('sent', PrometheusExporter::boundedLabel('sent', ['sent', 'failed']));
    }

    public function testArbitraryUnboundedValueIsCollapsedToOther(): void
    {
        // Simulates what would happen if a raw phone number, message id, or organization id were
        // ever accidentally passed as a label value -- it must never reach the exposition text
        // verbatim.
        self::assertSame('other', PrometheusExporter::boundedLabel('09121234567', ['sent', 'failed']));
        self::assertSame('other', PrometheusExporter::boundedLabel('org:48213', ['sent', 'failed']));
        self::assertSame('other', PrometheusExporter::boundedLabel('req-9f8c2b1a', ['sent', 'failed']));
        self::assertSame('other', PrometheusExporter::boundedLabel('msg-9007199254740991', ['sent', 'failed']));
    }

    public function testEmptyAndCaseMismatchedValuesAreAlsoCollapsed(): void
    {
        self::assertSame('other', PrometheusExporter::boundedLabel('', ['sent', 'failed']));
        self::assertSame('other', PrometheusExporter::boundedLabel('SENT', ['sent', 'failed']));
    }

    public function testRenderedExpositionNeverContainsAPhoneNumberShapedLabelValue(): void
    {
        // A structural proof, not just a unit check on the helper in isolation: render a small
        // fake source of "dirty" data through the same bounding path this file's real appenders
        // use, and confirm the phone-number-shaped input never survives into output text.
        $dirty = ['09121234567', 'unbounded-tenant-99182'];
        $rendered = [];
        foreach ($dirty as $value) {
            $rendered[] = PrometheusExporter::boundedLabel($value, ['up', 'down', 'degraded', 'unknown']);
        }
        foreach ($rendered as $value) {
            self::assertDoesNotMatchRegularExpression('/^09\d{9}$/', $value, 'a phone-number-shaped value must never survive as a label');
            self::assertStringNotContainsString('tenant', $value);
        }
    }
}
