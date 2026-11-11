<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CSV / formula injection: text someone else wrote (an inbound SMS, a contact name) must not run as
 * a formula when a customer opens an export in a spreadsheet. See csv_safe_cell(), app/bootstrap.php.
 */
final class CsvSafeCellTest extends TestCase
{
    public function testFormulaLikeTextGetsALeadingApostrophe(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil.example\",\"click\")", \csv_safe_cell('=HYPERLINK("http://evil.example","click")'));
        $this->assertSame("'+cmd|' /C calc'!A0", \csv_safe_cell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3+cmd|' /C calc'!A0", \csv_safe_cell("-2+3+cmd|' /C calc'!A0"));
        $this->assertSame("'@SUM(1+1)", \csv_safe_cell('@SUM(1+1)'));
        $this->assertSame("'\t=1+1", \csv_safe_cell("\t=1+1"));
        $this->assertSame("'\r=1+1", \csv_safe_cell("\r=1+1"));
    }

    public function testOrdinaryTextIsUnchanged(): void
    {
        $this->assertSame('سلام، سفارش شما ثبت شد', \csv_safe_cell('سلام، سفارش شما ثبت شد'));
        $this->assertSame('a=b', \csv_safe_cell('a=b'));
        $this->assertSame('', \csv_safe_cell(''));
        $this->assertSame('', \csv_safe_cell(null));
    }

    public function testNumbersStayNumbers(): void
    {
        // A phone number with a plus, a negative amount: data, not formulas.
        $this->assertSame('+989121234567', \csv_safe_cell('+989121234567'));
        $this->assertSame('-500', \csv_safe_cell('-500'));
        $this->assertSame(-500, \csv_safe_cell(-500));
        $this->assertSame(12.5, \csv_safe_cell(12.5));
    }

    public function testRowAppliesToEveryCellAndDropsKeys(): void
    {
        $this->assertSame([7, "'=1+1", 'ok'], \csv_safe_row(['id' => 7, 'content' => '=1+1', 'x' => 'ok']));
    }
}
