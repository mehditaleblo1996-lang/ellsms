<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The rules a NEW password must meet and the Argon2id "hash wrapping" (app/PasswordSecurity.php).
 * Nothing here touches the database.
 */
final class PasswordPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PASSWORD_HASH_MODE');
    }

    public function testAcceptablePasswordHasNoProblems(): void
    {
        $this->assertSame([], \password_policy_problems('datis2026x', 'ali'));
        $this->assertNull(\password_policy_error('رمزخوب۱۲۳۴', 'ali'));
    }

    public function testEachRuleIsReported(): void
    {
        $this->assertSame(['length'], \password_policy_problems('abc123'));
        $this->assertSame(['letter'], \password_policy_problems('12345678'));
        $this->assertSame(['digit'], \password_policy_problems('abcdefgh'));
        $this->assertSame(['username'], \password_policy_problems('Ali12345', 'ali12345'));
        $this->assertSame(['length'], \password_policy_problems(str_repeat('a1', 65)));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // Seven Persian characters are 14 bytes: still too short.
        $this->assertContains('length', \password_policy_problems('رمزعبو1'));
    }

    public function testErrorMessageNamesTheBrokenRules(): void
    {
        $message = \password_policy_error('abc');
        $this->assertNotNull($message);
        $this->assertStringContainsString('حداقل ۸ نویسه', $message);
        $this->assertStringContainsString('یک عدد', $message);
        $this->assertStringNotContainsString('یک حرف', $message);
    }

    public function testHintAndInputAttributesCarryTheHooksTheLiveCheckUses(): void
    {
        $hint = \password_policy_hint_html();
        foreach (['length', 'letter', 'digit', 'username'] as $rule) {
            $this->assertStringContainsString('data-rule="' . $rule . '"', $hint);
        }
        $this->assertStringNotContainsString('data-rule="username"', \password_policy_hint_html(false));
        $attrs = \password_policy_input_attrs('a"b', 'username');
        $this->assertStringContainsString('minlength="8"', $attrs);
        $this->assertStringContainsString('data-pw-policy', $attrs);
        $this->assertStringContainsString('data-pw-username="a&quot;b"', $attrs);
        $this->assertStringContainsString('data-pw-username-field="username"', $attrs);
    }

    public function testHashModeDefaultsToLegacy(): void
    {
        putenv('PASSWORD_HASH_MODE');
        $this->assertSame('legacy', \password_hash_mode());
        putenv('PASSWORD_HASH_MODE=something-else');
        $this->assertSame('legacy', \password_hash_mode());
        putenv('PASSWORD_HASH_MODE=Argon2id');
        $this->assertSame('argon2id', \password_hash_mode());
    }

    public function testWrappedVerifierIsBuiltFromTheStoredDigestAndChecksThePassword(): void
    {
        // What the conversion does: it holds only the SHA-256 digest, never the password.
        $storedDigest = \backend_hash_password('datis2026x');
        [$algo, $algoName] = \password_modern_algo();
        $this->assertContains($algoName, \PASSWORD_WRAPPED_ALGOS);
        $verifier = password_hash(\password_wrap_input($storedDigest), $algo);

        // What sign-in does afterwards: it holds the password.
        $this->assertTrue(password_verify(\password_wrap_input(\backend_hash_password('datis2026x')), $verifier));
        $this->assertFalse(password_verify(\password_wrap_input(\backend_hash_password('datis2026y')), $verifier));
        $this->assertSame(64, strlen(\password_wrap_input($storedDigest)));
    }
}
