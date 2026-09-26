<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/import_reader.php';

/**
 * Phase 14: streaming import reader tests.
 *
 * These prove that import_read_chunks() can process CSV and XLSX files in
 * bounded-memory chunks without materializing the whole file as a PHP array.
 */
final class ImportReaderTest extends TestCase
{
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    private function stagePath(string $storageKey): string {
        $path = APP_ROOT . '/storage/' . $storageKey;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create test storage dir');
        }
        return $path;
    }

    private function makeCsv(string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'import_csv_') . '.csv';
        $this->tmpFiles[] = $path;
        file_put_contents($path, $content);
        return $path;
    }

    private function makeXlsx(string $sheetXml, ?string $sharedStringsXml = null): string {
        $path = tempnam(sys_get_temp_dir(), 'import_xlsx_') . '.xlsx';
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

    public function testCsvStreamingChunksRespectChunkSize(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "0912000000{$i},Hello {$i}";
        }
        $path = $this->makeCsv(implode("\n", $lines));
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $chunks = iterator_to_array(import_read_chunks($storageKey, 3));

        $this->assertCount(4, $chunks); // 3 + 3 + 3 + 1
        $this->assertCount(3, $chunks[0]);
        $this->assertCount(1, $chunks[3]);
        $this->assertSame('989120000001', $chunks[0][0]['mobile']);
        $this->assertSame('Hello 10', $chunks[3][0]['content']);
    }

    public function testCsvHeaderRowIsSkipped(): void
    {
        $path = $this->makeCsv("mobile,message\n09120000001,Hello");
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $chunks = iterator_to_array(import_read_chunks($storageKey, 10));

        $this->assertCount(1, $chunks[0]);
        $this->assertSame('989120000001', $chunks[0][0]['mobile']);
    }

    public function testCsvPersianContentSurvives(): void
    {
        $path = $this->makeCsv("09120000001,سلام، \"نقل\"\n09120000002,\"خط\nجدید\"");
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $chunks = iterator_to_array(import_read_chunks($storageKey, 10));

        $this->assertSame('سلام، "نقل"', $chunks[0][0]['content']);
        $this->assertSame("خط\nجدید", $chunks[0][1]['content']);
    }

    public function testXlsxStreamingReadsSharedStringsAndInline(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not loaded.');
        }
        $sheetXml = '<?xml version="1.0"?><worksheet><sheetData>'
            . '<row><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row><c r="A2" t="inlineStr"><is><t>09120000002</t></is></c><c r="B2" t="inlineStr"><is><t>inline</t></is></c></row>'
            . '</sheetData></worksheet>';
        $shared = '<?xml version="1.0"?><sst count="2"><si><t>09120000001</t></si><si><t>shared</t></si></sst>';
        $path = $this->makeXlsx($sheetXml, $shared);
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $chunks = iterator_to_array(import_read_chunks($storageKey, 1));

        $this->assertCount(2, $chunks);
        $this->assertSame('989120000001', $chunks[0][0]['mobile']);
        $this->assertSame('shared', $chunks[0][0]['content']);
        $this->assertSame('989120000002', $chunks[1][0]['mobile']);
        $this->assertSame('inline', $chunks[1][0]['content']);
    }

    public function testCountRowsStreamsCsvWithoutMaterializing(): void
    {
        $path = $this->makeCsv("09120000001,Hello\n09120000002,World");
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $result = import_count_rows($storageKey);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count']);
    }

    public function testCountRowsCountsCsvRecordsNotLinesWhenCellsContainNewlines(): void
    {
        $path = $this->makeCsv("\xEF\xBB\xBFmobile,message,n\r\n09120000001,\"line1\nline2\n{n}\",3\r\n09120000002,\"a\nb\",4\r\n");
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $result = import_count_rows($storageKey);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['count']);
        $rows = import_read_row_range($storageKey, 1, $result['count']);
        $this->assertCount(2, $rows);
        $this->assertSame("line1\nline2\n{n}", $rows[0]['content']);
    }

    public function testReadHeaderCellsReturnsHeaderRowThatRowRangeSkips(): void
    {
        $path = $this->makeCsv("\xEF\xBB\xBFموبایل,متن,تعداد\n09120000001,x {تعداد},2");
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $this->assertSame([], import_read_row_range($storageKey, 1, 1));
        $this->assertSame(['موبایل', 'متن', 'تعداد'], import_read_header_cells($storageKey));
    }

    public function testReadHeaderCellsFromXlsx(): void
    {
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>mobile</t></is></c><c r="B1" t="inlineStr"><is><t>text</t></is></c><c r="C1" t="inlineStr"><is><t>n</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>09120000001</t></is></c><c r="B2" t="inlineStr"><is><t>hi {n}</t></is></c><c r="C2"><v>5</v></c></row>'
            . '</sheetData></worksheet>';
        $path = $this->makeXlsx($sheet);
        $storageKey = 'imports/test/' . basename($path);
        $staged = $this->stagePath($storageKey);
        copy($path, $staged);
        $this->tmpFiles[] = $staged;

        $this->assertSame(['mobile', 'text', 'n'], import_read_header_cells($storageKey));
    }
}
