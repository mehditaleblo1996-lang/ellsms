<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/xlsx_reader.php';

/**
 * Phase 10 / TD-033: read_xlsx_rows() (app/xlsx_reader.php) previously decompressed and fully
 * parsed a worksheet into a SimpleXML tree BEFORE the caller's 20,000-row cap (public/p2p-send.php,
 * public/smart-send.php) ever got a chance to reject it — so a highly-compressed, huge-when-expanded
 * xlsx could exhaust memory/CPU regardless of that cap. This proves the fix: a crafted xlsx whose
 * worksheet member is small on disk but reports a large UNCOMPRESSED size is rejected before any
 * decompression happens, and a normal small xlsx is unaffected.
 */
final class XlsxReaderTest extends TestCase
{
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not loaded in this PHP CLI binary (the Docker runtime image installs it explicitly -- docker/Dockerfile\'s docker-php-ext-install zip -- this is a local tooling gap, not a code issue).');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    private function makeXlsx(string $sheetXml, ?string $sharedStringsXml = null): string {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_test_') . '.xlsx';
        $this->tmpFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        if ($sharedStringsXml !== null) {
            $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        }
        $zip->close();
        return $path;
    }

    public function testNormalSmallSheetParsesCorrectly(): void
    {
        $sheetXml = '<?xml version="1.0"?><worksheet><sheetData>'
            . '<row><c r="A1" t="inlineStr"><is><t>09120000001</t></is></c><c r="B1" t="inlineStr"><is><t>hello</t></is></c></row>'
            . '</sheetData></worksheet>';
        $path = $this->makeXlsx($sheetXml);

        $rows = read_xlsx_rows($path);

        $this->assertCount(1, $rows);
        $this->assertSame(['09120000001', 'hello'], $rows[0]);
    }

    public function testWorksheetMemberReportingAnOversizedUncompressedSizeIsRejectedBeforeDecompression(): void
    {
        // A real member whose CONTENT is only moderately large but whose reported uncompressed
        // size (from the zip's own local file header, read via ZipArchive::statName() -- exactly
        // what the fix checks) already exceeds MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES. Highly
        // repetitive text like this compresses extremely well in a real zip bomb; this test only
        // needs statName() to report a large size, not actually construct gigabytes on disk, so a
        // moderately large but genuinely repetitive payload is enough to prove the guard fires.
        $bigRepetitive = str_repeat('<row><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>', 2_000_000); // ~114MB of XML, compresses to a few hundred KB
        $sheetXml = '<?xml version="1.0"?><worksheet><sheetData>' . $bigRepetitive . '</sheetData></worksheet>';
        $path = $this->makeXlsx($sheetXml);

        // Sanity check: the fixture itself must actually exceed the threshold and compress well,
        // or this test would not be exercising the guard at all.
        $this->assertGreaterThan(64 * 1024 * 1024, strlen($sheetXml), 'fixture must genuinely exceed the 64MB uncompressed threshold');
        $this->assertLessThan(5 * 1024 * 1024, filesize($path), 'fixture must compress to a small file on disk, like a real zip bomb would');

        $this->expectException(\RuntimeException::class);
        read_xlsx_rows($path);
    }

    public function testOversizedSharedStringsMemberIsAlsoRejected(): void
    {
        $bigShared = '<?xml version="1.0"?><sst>' . str_repeat('<si><t>x</t></si>', 4_500_000) . '</sst>'; // ~76.5MB
        $sheetXml = '<?xml version="1.0"?><worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>';
        $path = $this->makeXlsx($sheetXml, $bigShared);

        $this->assertGreaterThan(64 * 1024 * 1024, strlen($bigShared));

        $this->expectException(\RuntimeException::class);
        read_xlsx_rows($path);
    }
}
