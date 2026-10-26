<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Captcha.php — challenge issuing and payload verification for the self-hosted proof-of-work
 * CAPTCHA, solved here the same way the browser worker does (nonce || uint32 counter, PBKDF2).
 * The "after N failures" counting is rate_limit_count()/rate_limit_record() over the database and
 * is not covered here.
 */
final class CaptchaTest extends TestCase
{
    /** @var array<string, true> */
    private array $usedNonces = [];

    protected function setUp(): void
    {
        putenv('CAPTCHA_HMAC_SECRET=unit-test-captcha-secret-0123456789abcdef');
        putenv('CAPTCHA_POW_COST=10');
        putenv('CAPTCHA_POW_MAX_COUNTER=40');
        $this->usedNonces = [];
    }

    protected function tearDown(): void
    {
        putenv('CAPTCHA_HMAC_SECRET');
        putenv('CAPTCHA_POW_COST');
        putenv('CAPTCHA_POW_MAX_COUNTER');
    }

    private function consume(): callable
    {
        return function (string $nonce): bool {
            if (isset($this->usedNonces[$nonce])) return false;
            $this->usedNonces[$nonce] = true;
            return true;
        };
    }

    private function solve(array $challenge): int
    {
        $p = $challenge['parameters'];
        for ($counter = 0; $counter <= 1000; $counter++) {
            $key = hash_pbkdf2('sha256', hex2bin($p['nonce']) . pack('N', $counter), hex2bin($p['salt']), $p['cost'], $p['keyLength'], true);
            if (str_starts_with($key, hex2bin($p['keyPrefix']))) return $counter;
        }
        self::fail('challenge not solvable');
    }

    private function payload(array $challenge, int $counter): string
    {
        return base64_encode(json_encode([
            'challenge' => $challenge,
            'solution' => ['counter' => $counter, 'derivedKey' => '', 'time' => 1.2],
        ]));
    }

    public function testChallengeHasTheWidgetShapeAndASecretCounterInRange(): void
    {
        $challenge = captcha_create_challenge();
        $p = $challenge['parameters'];

        self::assertSame(['algorithm', 'cost', 'expiresAt', 'keyLength', 'keyPrefix', 'nonce', 'salt'], array_keys($p));
        self::assertSame('PBKDF2/SHA-256', $p['algorithm']);
        self::assertSame(10, $p['cost']);
        self::assertSame(32, $p['keyLength']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $p['keyPrefix']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $challenge['signature']);
        self::assertEqualsWithDelta(time() + 600, $p['expiresAt'], 2);

        $counter = $this->solve($challenge);
        self::assertGreaterThanOrEqual(20, $counter);
        self::assertLessThanOrEqual(40, $counter);
    }

    public function testSolvedChallengeVerifiesExactlyOnce(): void
    {
        $challenge = captcha_create_challenge();
        $payload = $this->payload($challenge, $this->solve($challenge));

        self::assertTrue(captcha_verify_payload($payload, $this->consume()));
        self::assertFalse(captcha_verify_payload($payload, $this->consume()), 'a solved challenge must not be replayable');
    }

    public function testWrongCounterIsRejected(): void
    {
        $challenge = captcha_create_challenge();
        $payload = $this->payload($challenge, $this->solve($challenge) + 1);

        self::assertFalse(captcha_verify_payload($payload, $this->consume()));
        self::assertSame([], $this->usedNonces, 'a failed proof must not burn the challenge');
    }

    public function testTamperedParametersBreakTheSignature(): void
    {
        $challenge = captcha_create_challenge();
        $counter = $this->solve($challenge);
        $challenge['parameters']['expiresAt'] += 3600;

        self::assertFalse(captcha_verify_payload($this->payload($challenge, $counter), $this->consume()));
    }

    public function testSelfMadeEasyChallengeIsRejected(): void
    {
        // A bot inventing a trivial challenge (cost 1, counter 0) cannot sign it.
        $nonce = str_repeat('a', 32);
        $salt = str_repeat('b', 32);
        $key = hash_pbkdf2('sha256', hex2bin($nonce) . pack('N', 0), hex2bin($salt), 1, 32, true);
        $challenge = ['parameters' => [
            'algorithm' => 'PBKDF2/SHA-256', 'cost' => 1, 'expiresAt' => time() + 600, 'keyLength' => 32,
            'keyPrefix' => bin2hex(substr($key, 0, 16)), 'nonce' => $nonce, 'salt' => $salt,
        ], 'signature' => str_repeat('0', 64)];

        self::assertFalse(captcha_verify_payload($this->payload($challenge, 0), $this->consume()));
    }

    public function testDifferentSecretRejects(): void
    {
        $challenge = captcha_create_challenge();
        $payload = $this->payload($challenge, $this->solve($challenge));
        putenv('CAPTCHA_HMAC_SECRET=another-install-secret-0123456789abcdef');

        self::assertFalse(captcha_verify_payload($payload, $this->consume()));
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $challenge = captcha_create_challenge(time() - 3600);
        $payload = $this->payload($challenge, $this->solve($challenge));

        self::assertFalse(captcha_verify_payload($payload, $this->consume()));
    }

    public function testWidgetTestModeAndGarbageAreRejected(): void
    {
        $consume = $this->consume();
        self::assertFalse(captcha_verify_payload('', $consume));
        self::assertFalse(captcha_verify_payload('not base64 !!', $consume));
        self::assertFalse(captcha_verify_payload(base64_encode('{"challenge":null,"solution":null,"test":true}'), $consume));
        self::assertFalse(captcha_verify_payload(base64_encode('[]'), $consume));
        self::assertFalse(captcha_verify_payload(str_repeat('A', 5000), $consume));
    }

    public function testWidgetMarkupPointsAtTheSelfHostedAssets(): void
    {
        $html = captcha_widget();

        self::assertStringContainsString('<altcha-widget challenge="/captcha/challenge" name="altcha"', $html);
        self::assertStringContainsString('/assets/vendor/altcha-3.2.4/altcha.min.js', $html);
        self::assertStringContainsString('/assets/js/captcha.js', $html);
        self::assertStringNotContainsString('http', $html, 'no third-party origin may be involved');
        self::assertFileExists(dirname(__DIR__, 2) . '/public/assets/vendor/altcha-3.2.4/pbkdf2.js');
    }
}
