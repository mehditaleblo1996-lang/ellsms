<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/TotpMfa.php';

final class TotpMfaTest extends TestCase
{
    public function testBase32RoundTripAndKnownHotpVector(): void
    {
        $raw = '12345678901234567890';
        $encoded = totp_base32_encode($raw);
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encoded);
        self::assertSame($raw, totp_base32_decode($encoded));
        self::assertSame('287082', totp_code_for_step($encoded, 1));
        self::assertSame('359152', totp_code_for_step($encoded, 2));
    }

    public function testAuthenticatorLoginWiringAndStorageMigrationExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/db/migrations/2026_08_26_totp_mfa.sql');
        self::assertFileExists($root . '/public/account/security/index.php');

        $login = (string)file_get_contents($root . '/public/login.php');
        self::assertStringContainsString("totp_enabled((int)\$u['id'])", $login);
        self::assertStringContainsString("\$_SESSION['twofa_method'] = 'choose'", $login);

        $verify = (string)file_get_contents($root . '/public/verify-2fa.php');
        self::assertStringContainsString("totp_verify_user((int)\$u['id'], \$code)", $verify);
        self::assertStringContainsString("choose_totp", $verify);
        self::assertStringContainsString("choose_sms", $verify);
        self::assertStringContainsString("switch_sms", $verify);
        self::assertStringContainsString("switch_totp", $verify);
    }

    public function testTotpSecretIsEncryptedReplayProtectedAndQrIsLocal(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string)file_get_contents($root . '/app/TotpMfa.php');
        self::assertStringContainsString("openssl_encrypt(\$secretBase32, 'aes-256-gcm'", $source);
        self::assertStringContainsString('last_used_step', $source);
        self::assertStringContainsString('auth.totp.replay_blocked', $source);
        self::assertStringContainsString("['qrencode', '-t', 'SVG'", $source);
        self::assertStringNotContainsString('quickchart', strtolower($source));
        self::assertStringNotContainsString('googleapis', strtolower($source));
        self::assertStringNotContainsString('secret_plaintext', $source);

        $dockerfile = (string)file_get_contents($root . '/docker/Dockerfile');
        self::assertStringContainsString('qrencode', $dockerfile);
    }

    public function testSecurityPageAllowsSelfServiceSmsAndAuthenticatorEnrollment(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/public/account/security/index.php');
        self::assertStringContainsString("value=\"sms_enable\"", $source);
        self::assertStringContainsString("value=\"sms_disable\"", $source);
        self::assertStringContainsString('totp_qr_svg_data_uri', $source);
        self::assertStringContainsString('هر دو را فعال کنید', $source);
    }
}
