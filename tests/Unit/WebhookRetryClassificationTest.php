<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Phase 12 (STEP 34): the exact retryable/permanent HTTP-status classification cron/webhook-worker.php relies on. */
final class WebhookRetryClassificationTest extends TestCase
{
    public function testRetryableStatusesAreRetryable(): void
    {
        foreach ([408, 425, 429, 500, 502, 503, 504] as $status) {
            $this->assertTrue(\webhook_http_status_is_retryable($status), "expected {$status} to be retryable");
        }
    }

    public function testPermanentStatusesAreNotRetryable(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $status) {
            $this->assertFalse(\webhook_http_status_is_retryable($status), "expected {$status} to be permanent");
        }
    }

    public function testUnlistedServerErrorDefaultsRetryable(): void
    {
        $this->assertTrue(\webhook_http_status_is_retryable(599));
    }

    public function testUnlistedClientErrorDefaultsPermanent(): void
    {
        $this->assertFalse(\webhook_http_status_is_retryable(451));
    }
}
