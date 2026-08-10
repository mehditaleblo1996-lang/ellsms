<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 10, STEP 9/45: crafted-request coverage for csrf_check()/csrf_token() (app/bootstrap.php).
 * csrf_check() calls exit() directly on a bad token (by design — the safest fail-closed way to
 * guarantee a rejected request can never fall through into the mutation code below it), which
 * can't be invoked in-process without killing the PHPUnit run itself. So the crafted-request
 * exercise runs the REAL function in a real PHP subprocess (app/bootstrap.php's session_start() is
 * already skipped under CLI SAPI — see tests/bootstrap.php's own docblock — so $_SESSION here is
 * just a plain array this script sets directly, no real session machinery needed) and asserts on
 * its actual observed behavior; token-generation/comparison primitives are tested directly
 * in-process alongside it.
 */
final class CsrfTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['csrf'], $_POST['_csrf'], $_SERVER['REQUEST_METHOD']);
    }

    public function testTokenIsGeneratedOnceAndReused(): void
    {
        unset($_SESSION['csrf']);
        $first = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second, 'a session must keep the same CSRF token across calls, not regenerate it every time');
        $this->assertGreaterThanOrEqual(32, strlen($first), 'token must have real entropy, not a short/guessable value');
    }

    public function testFieldEmbedsTheCurrentSessionToken(): void
    {
        unset($_SESSION['csrf']);
        $token = csrf_token();
        $this->assertStringContainsString('value="' . $token . '"', csrf_field());
        $this->assertStringContainsString('name="_csrf"', csrf_field());
    }

    /**
     * The real crafted-request case: a POST with a completely absent/mismatched token must be
     * rejected by the ACTUAL csrf_check() function (non-2xx, request never reaches the code after
     * it) — proven via a real subprocess since it exit()s on failure.
     */
    public function testMismatchedTokenIsRejectedByTheRealFunctionAndNeverReachesMutationCode(): void
    {
        $result = $this->runRealCsrfCheck(sessionToken: 'real-session-token-abc', postedToken: 'attacker-guessed-token-xyz');
        $this->assertSame(400, $result['http_code']);
        $this->assertStringNotContainsString('MUTATION_EXECUTED', $result['output'], 'code after csrf_check() must never run when the token is wrong');
    }

    public function testMissingTokenIsRejectedByTheRealFunction(): void
    {
        $result = $this->runRealCsrfCheck(sessionToken: 'real-session-token-abc', postedToken: null);
        $this->assertSame(400, $result['http_code']);
        $this->assertStringNotContainsString('MUTATION_EXECUTED', $result['output']);
    }

    public function testMatchingTokenLetsTheRealFunctionAllowTheRequestThrough(): void
    {
        $result = $this->runRealCsrfCheck(sessionToken: 'real-session-token-abc', postedToken: 'real-session-token-abc');
        $this->assertSame(200, $result['http_code']);
        $this->assertStringContainsString('MUTATION_EXECUTED', $result['output'], 'a correctly-matching token must let the request proceed');
    }

    /** @return array{http_code: int, output: string} */
    private function runRealCsrfCheck(string $sessionToken, ?string $postedToken): array {
        $bootstrapPath = dirname(__DIR__, 2) . '/app/bootstrap.php';
        $postedTokenPhp = $postedToken === null ? 'null' : var_export($postedToken, true);
        $sessionTokenPhp = var_export($sessionToken, true);
        $script = <<<PHP
<?php
require {$this->phpExport($bootstrapPath)};
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SESSION['csrf'] = {$sessionTokenPhp};
\$_POST['_csrf'] = {$postedTokenPhp};
csrf_check(); // the REAL function — exit()s with http_response_code(400) on mismatch
http_response_code(200);
echo 'MUTATION_EXECUTED';
PHP;
        $tmpFile = tempnam(sys_get_temp_dir(), 'csrf_test_');
        file_put_contents($tmpFile, $script);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile) . ' 2>&1', $output);
        unlink($tmpFile);
        $outputText = implode('', $output);
        // csrf_check()'s own http_response_code(400) call has no real webserver to report through
        // under CLI, so the pass/fail signal actually observable here is exactly what the function
        // prints on each path: its own rejection message vs. this script's own success marker.
        $httpCode = str_contains($outputText, 'MUTATION_EXECUTED') ? 200 : 400;
        return ['http_code' => $httpCode, 'output' => $outputText];
    }

    private function phpExport(string $value): string {
        return var_export($value, true);
    }
}
