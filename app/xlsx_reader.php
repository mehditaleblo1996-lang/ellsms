<?php
/**
 * ELLSMS — minimal spreadsheet reader for bulk-send uploads.
 *
 * Deliberately NOT PhpSpreadsheet or any Composer package — this project
 * has no vendor/ directory anywhere else, and the actual need here is
 * narrow (read plain cell text/numbers from the first sheet of a simple
 * export, no formulas/styles/merged cells). XLSX is just a ZIP of XML
 * files, and PHP ships ZipArchive + SimpleXML, so a ~120-line reader
 * covers it without adding a dependency-management story to the project.
 *
 * Supports .xlsx (via this hand-written parser) and .csv (native
 * str_getcsv) — auto-detected by extension. Returns a plain array of
 * rows, each row a plain array of string cell values, 0-indexed,
 * gaps filled with ''.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Read a spreadsheet upload into rows of string values.
 * Throws RuntimeException with a Persian message on any failure.
 */
function read_spreadsheet_rows(string $tmpPath, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return read_csv_rows($tmpPath);
    }
    if ($ext === 'xlsx') {
        return read_xlsx_rows($tmpPath);
    }
    throw new RuntimeException('فرمت فایل باید xlsx یا csv باشد.');
}

function read_csv_rows(string $path): array {
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('باز کردن فایل ممکن نشد.');
    // Strip a UTF-8 BOM if present, so column A of row 1 parses cleanly.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = array_map(fn($v) => trim((string)$v), $row);
    }
    fclose($fh);
    return $rows;
}

function read_xlsx_rows(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل xlsx معتبر نیست یا خراب است.');
    }

    // Shared strings table (most text cells reference this by index
    // instead of storing the text inline).
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                // <si><t>text</t></si> OR <si><r><t>run</t></r>...</si> (rich text runs)
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) $text .= (string)$run->t;
                    $shared[] = $text;
                }
            }
        }
    }

    // Find the first worksheet. Try the common default path first,
    // then fall back to whatever worksheets/*.xml exists.
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('صفحه‌ای در فایل xlsx پیدا نشد.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('خواندن محتوای فایل xlsx ممکن نشد.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowEl) {
        $rowValues = [];
        foreach ($rowEl->c as $cellEl) {
            $ref = (string)$cellEl['r']; // e.g. "C7"
            $col = xlsx_col_index($ref);
            $type = (string)$cellEl['t'];

            if ($type === 'inlineStr') {
                $value = isset($cellEl->is->t) ? (string)$cellEl->is->t : '';
            } elseif ($type === 's') {
                $idx = (int)$cellEl->v;
                $value = $shared[$idx] ?? '';
            } else {
                $value = isset($cellEl->v) ? (string)$cellEl->v : '';
            }
            $rowValues[$col] = trim($value);
        }
        if (!$rowValues) continue;
        $maxCol = max(array_keys($rowValues));
        $out = [];
        for ($c = 0; $c <= $maxCol; $c++) $out[] = $rowValues[$c] ?? '';
        $rows[] = $out;
    }
    return $rows;
}

/** "C7" -> 2 (0-indexed column number). */
function xlsx_col_index(string $cellRef): int {
    preg_match('/^([A-Z]+)/', $cellRef, $m);
    $letters = $m[1] ?? 'A';
    $col = 0;
    foreach (str_split($letters) as $ch) {
        $col = $col * 26 + (ord($ch) - ord('A') + 1);
    }
    return $col - 1;
}
