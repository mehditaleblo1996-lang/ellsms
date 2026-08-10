<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * autoreply_matches() / render_bulk_template() (app/backend.php) — the
 * matching and templating logic behind منشی پیامک and بلک پرسنالایز
 * sending. Both are pure string functions with no DB/network dependency,
 * making them safe, high-value targets for locking in behavior that's
 * easy to accidentally change while refactoring app/backend.php later
 * (see docs/technical-debt.md TD-021 on that file's size).
 */
final class AutoreplyMatchingTest extends TestCase
{
    public function testExactMatchIsCaseInsensitive(): void
    {
        $this->assertTrue(autoreply_matches('Hello', 'hello', 'exact'));
    }

    public function testExactMatchRejectsPartialContent(): void
    {
        $this->assertFalse(autoreply_matches('Hello World', 'hello', 'exact'));
    }

    public function testStartsWithMatchesPrefixOnly(): void
    {
        $this->assertTrue(autoreply_matches('STOP please', 'stop', 'starts_with'));
        $this->assertFalse(autoreply_matches('please STOP', 'stop', 'starts_with'));
    }

    public function testContainsMatchesAnywhereInContent(): void
    {
        $this->assertTrue(autoreply_matches('please STOP now', 'stop', 'contains'));
    }

    public function testEmptyKeywordNeverMatches(): void
    {
        $this->assertFalse(autoreply_matches('anything', '', 'exact'));
        $this->assertFalse(autoreply_matches('anything', '   ', 'contains'));
    }

    public function testPersianDigitsInContentAndKeywordAreNormalizedBeforeComparison(): void
    {
        // A customer's inbound SMS could use Persian digits where the rule
        // was configured with ASCII ones (or vice versa) — matching must
        // not be sensitive to which digit form either side used.
        $this->assertTrue(autoreply_matches('کد ۱۲۳ را وارد کنید', 'کد 123', 'starts_with'));
    }

    public function testUnknownMatchTypeFallsBackToExact(): void
    {
        $this->assertTrue(autoreply_matches('hello', 'hello', 'not-a-real-type'));
        $this->assertFalse(autoreply_matches('hello world', 'hello', 'not-a-real-type'));
    }

    public function testRenderBulkTemplateSubstitutesKnownVariables(): void
    {
        $result = render_bulk_template('سلام {نام}، مبلغ {مبلغ} تومان', ['نام' => 'علی', 'مبلغ' => '50000']);
        $this->assertSame('سلام علی، مبلغ 50000 تومان', $result);
    }

    /**
     * An unmatched placeholder must stay literal, not be silently blanked —
     * this is an explicit, deliberate business rule (README: "so a typo in
     * a column name is obvious immediately"), not incidental behavior.
     */
    public function testRenderBulkTemplateLeavesUnmatchedPlaceholdersLiteral(): void
    {
        $result = render_bulk_template('سلام {نام}، کد {ناموجود}', ['نام' => 'علی']);
        $this->assertSame('سلام علی، کد {ناموجود}', $result);
    }
}
