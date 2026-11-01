<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/** The admin-only "provider's full answer" in the send report's message detail. */
final class SentReportProviderResponseTest extends TestCase
{
    public function testJsonAnswerIsPrettyPrintedWithReadablePersianAndExactIds(): void
    {
        $raw = '{"result":[-11,28308298531],"message":"عملیات"}';
        $pretty = sent_report_pretty_response($raw);
        self::assertStringContainsString('"message": "عملیات"', $pretty);
        self::assertStringContainsString('-11', $pretty);
        self::assertStringContainsString('28308298531', $pretty);
    }

    public function testNonJsonAnswerIsShownUnchanged(): void
    {
        self::assertSame('Bad Gateway', sent_report_pretty_response('Bad Gateway'));
        self::assertSame('', sent_report_pretty_response(''));
    }

    public function testNoAuditRowMeansNothingToShow(): void
    {
        self::assertNull(sent_report_provider_response(0));
    }
}
