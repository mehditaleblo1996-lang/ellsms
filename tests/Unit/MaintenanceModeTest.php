<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 22/23: pure-logic half of maintenance mode (app/maintenance.php) -- flag
 * file detection, custom vs default message, and the exempt-script allowlist. The actual
 * HTTP-response behavior (503 for blocked pages, pass-through for exempt ones) is proven against
 * a real running server in tests/Integration/MaintenanceModeHttpTest.php.
 */
final class MaintenanceModeTest extends TestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        $this->flagFile = sys_get_temp_dir() . '/ellsms_maint_unit_' . bin2hex(random_bytes(6)) . '.flag';
        putenv('MAINTENANCE_MODE_FILE=' . $this->flagFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->flagFile);
        putenv('MAINTENANCE_MODE_FILE');
    }

    public function testInactiveWhenFlagFileAbsent(): void
    {
        $this->assertFalse(\maintenance_mode_active());
    }

    public function testActiveWhenFlagFilePresent(): void
    {
        file_put_contents($this->flagFile, '');
        $this->assertTrue(\maintenance_mode_active());
    }

    public function testDefaultMessageWhenFlagFileEmpty(): void
    {
        file_put_contents($this->flagFile, '');
        $this->assertStringContainsString('به‌روزرسانی', \maintenance_mode_message());
    }

    public function testCustomMessageFromFlagFileContents(): void
    {
        file_put_contents($this->flagFile, 'Scheduled release in progress, back at 14:00 UTC.');
        $this->assertSame('Scheduled release in progress, back at 14:00 UTC.', \maintenance_mode_message());
    }

    public function testHealthAndReadinessAndPaymentCallbackAreExempt(): void
    {
        $this->assertContains('health.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
        $this->assertContains('health-ready.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
        $this->assertContains('zarinpal-callback.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
    }

    public function testLoginAndSendPagesAreNotExempt(): void
    {
        $this->assertNotContains('login.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
        $this->assertNotContains('new-send.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
        $this->assertNotContains('buy-credit.php', \MAINTENANCE_MODE_EXEMPT_SCRIPTS);
    }

    public function testCurrentScriptExemptMatchesOnBasenameOnly(): void
    {
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/public/health.php';
        try {
            $this->assertTrue(\maintenance_mode_current_script_exempt());
            $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/public/login.php';
            $this->assertFalse(\maintenance_mode_current_script_exempt());
        } finally {
            if ($original === null) unset($_SERVER['SCRIPT_FILENAME']); else $_SERVER['SCRIPT_FILENAME'] = $original;
        }
    }
}
