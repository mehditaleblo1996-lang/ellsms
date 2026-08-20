<?php
/**
 * ELLSMS — streaming file readers for large SMS imports.
 *
 * These readers deliberately return rows one at a time (via a callable or a
 * generator) so a 1M-row file never becomes a 1M-element PHP array. Callers
 * batch rows into chunks in memory sized by IMPORT_CHUNK_SIZE.
 *
 * Supported formats:
 *   - CSV / TXT: first column mobile, second column message. Optional header row.
 *   - XLSX: first sheet only, first column mobile, second column message.
 *            Shared strings are loaded into memory (bounded by
 *            MAX_XLSX_SHARED_STRINGS_BYTES), but the worksheet itself is streamed
 *            via XMLReader so row count does not affect peak memory.
 *
 * All paths validate extensions and MIME types are NOT trusted — extensions are
 * checked, and CSV/TXT files are read as text; XLSX files are validated as ZIP
 * archives by opening them with ZipArchive.
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';

/** Maximum uncompressed size for xl/sharedStrings.xml before we refuse the file. */
const IMPORT_MAX_XLSX_SHARED_STRINGS_BYTES = 64 * 1024 * 1024;

/** Maximum upload size for import files (bytes). Operators may raise via env. */
function import_max_upload_bytes(): int {
    return max(1, (int)(env('IMPORT_MAX_UPLOAD_BYTES', (string)(128 * 1024 * 1024)) ?? (string)(128 * 1024 * 1024)));
}

/** Allowed extensions for import uploads. */
function import_allowed_extensions(): array {
    return ['csv', 'txt', 'xlsx'];
}

/**
 * Validate an uploaded import file.
 *
 * @return array{ok:bool, error:?string, ext:string}
 */
function import_validate_upload(array $file): array {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'فایل را انتخاب کنید.', 'ext' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'بارگذاری فایل با خطا مواجه شد.', 'ext' => ''];
    }
    if ((int)($file['size'] ?? 0) > import_max_upload_bytes()) {
        return ['ok' => false, 'error' => 'حجم فایل بیش از حد مجاز است.', 'ext' => ''];
    }

    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, import_allowed_extensions(), true)) {
        return ['ok' => false, 'error' => 'فرمت فایل باید csv، txt یا xlsx باشد.', 'ext' => $ext];
    }

    return ['ok' => true, 'error' => null, 'ext' => $ext];
}

/**
 * Move an uploaded file to a randomized storage key under storage/imports/.
 *
 * @return array{ok:bool, storage_key:string, error:?string}
 */
function import_store_upload(array $file): array {
    $dir = APP_ROOT . '/storage/imports';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'storage_key' => '', 'error' => 'امکان ساخت مسیر ذخیره‌سازی وجود ندارد.'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, import_allowed_extensions(), true)) {
        return ['ok' => false, 'storage_key' => '', 'error' => 'پسوند فایل نامعتبر است.'];
    }

    $storageKey = 'imports/' . date('Ymd') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = APP_ROOT . '/storage/' . $storageKey;
    $destDir = dirname($dest);
    if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
        return ['ok' => false, 'storage_key' => '', 'error' => 'امکان ساخت مسیر ذخیره‌سازی وجود ندارد.'];
    }

    if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $dest)) {
        return ['ok' => false, 'storage_key' => '', 'error' => 'ذخیره‌ی فایل با خطا مواجه شد.'];
    }

    return ['ok' => true, 'storage_key' => $storageKey, 'error' => null];
}

/**
 * Resolve the absolute filesystem path for a stored import key.
 */
function import_storage_path(string $storageKey): string {
    return APP_ROOT . '/storage/' . ltrim($storageKey, '/');
}

/**
 * Delete a stored import file. Safe to call even if the file is already gone.
 */
function import_delete_storage(string $storageKey): void {
    $path = import_storage_path($storageKey);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Count rows in a stored import file without loading it into memory.
 *
 * For CSV/TXT this streams lines. For XLSX it streams the worksheet XML.
 * The count includes header rows; callers decide whether to skip them.
 *
 * @return array{ok:bool, count:int, error:?string}
 */
function import_count_rows(string $storageKey): array {
    $path = import_storage_path($storageKey);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    try {
        if ($ext === 'xlsx') {
            return xlsx_count_rows($path);
        }
        return csv_count_rows($path);
    } catch (Throwable $t) {
        Logger::error('import.count_rows_failed', ['storage_key' => $storageKey, 'exception' => $t]);
        return ['ok' => false, 'count' => 0, 'error' => 'شمارش ردیف‌ها با خطا مواجه شد.'];
    }
}

function csv_count_rows(string $path): array {
    $fh = fopen($path, 'r');
    if (!$fh) {
        return ['ok' => false, 'count' => 0, 'error' => 'باز کردن فایل ممکن نشد.'];
    }

    $count = 0;
    while (fgets($fh) !== false) {
        $count++;
    }
    fclose($fh);
    return ['ok' => true, 'count' => $count, 'error' => null];
}

function xlsx_count_rows(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'count' => 0, 'error' => 'فایل xlsx معتبر نیست.'];
    }

    $sheetName = xlsx_first_sheet_name($zip);
    if ($sheetName === null) {
        $zip->close();
        return ['ok' => false, 'count' => 0, 'error' => 'صفحه‌ای در فایل xlsx پیدا نشد.'];
    }

    $stat = $zip->statName($sheetName);
    if ($stat !== false && (int)$stat['size'] > MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES) {
        $zip->close();
        return ['ok' => false, 'count' => 0, 'error' => 'فایل xlsx بیش از حد بزرگ است.'];
    }

    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetXml === false) {
        return ['ok' => false, 'count' => 0, 'error' => 'خواندن صفحه‌ی xlsx ممکن نشد.'];
    }

    $reader = new XMLReader();
    $reader->xml($sheetXml);

    $count = 0;
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
            $count++;
        }
    }
    $reader->close();
    return ['ok' => true, 'count' => $count, 'error' => null];
}

/**
 * Read rows from a stored import file in chunks.
 *
 * Returns a generator yielding arrays of up to $chunkSize rows, where each row
 * is ['mobile'=>string, 'content'=>string]. For CSV/TXT the content is column B;
 * for XLSX columns A and B. Header rows are skipped when the first cell of the
 * first row is not a valid mobile number.
 *
 * @return Generator<list<array{mobile:string,content:string}>>
 */
function import_read_chunks(string $storageKey, int $chunkSize): Generator {
    $path = import_storage_path($storageKey);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        yield from xlsx_read_chunks($path, $chunkSize);
    } else {
        yield from csv_read_chunks($path, $chunkSize);
    }
}

/**
 * Read a specific 1-indexed row range from the stored import file.
 *
 * This is used by the import worker to process the rows belonging to one
 * chunk. The range is inclusive on both ends. Header-row skipping matches
 * import_read_chunks().
 *
 * @return list<array{mobile:?string,content:string,row_no:int}>
 */
function import_read_row_range(string $storageKey, int $firstRow, int $lastRow): array {
    $path = import_storage_path($storageKey);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        return xlsx_read_row_range($path, $firstRow, $lastRow);
    }
    return csv_read_row_range($path, $firstRow, $lastRow);
}

function csv_read_row_range(string $path, int $firstRow, int $lastRow): array {
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('باز کردن فایل ممکن نشد.');
    }

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $rowNo = 0;
    $headerSkipped = false;
    $rows = [];

    while (($row = fgetcsv($fh)) !== false) {
        $rowNo++;
        $mobile = normalize_msisdn(trim((string)($row[0] ?? '')));
        $content = trim((string)($row[1] ?? ''));

        if (!$headerSkipped && $rowNo === 1 && $mobile === null) {
            $headerSkipped = true;
            continue;
        }
        $headerSkipped = true;

        if ($rowNo < $firstRow) {
            continue;
        }
        if ($rowNo > $lastRow) {
            break;
        }

        $rows[] = ['mobile' => $mobile, 'content' => $content, 'row_no' => $rowNo];
    }

    fclose($fh);
    return $rows;
}

function xlsx_read_row_range(string $path, int $firstRow, int $lastRow): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل xlsx معتبر نیست.');
    }

    $sheetName = xlsx_first_sheet_name($zip);
    if ($sheetName === null) {
        $zip->close();
        throw new RuntimeException('صفحه‌ای در فایل xlsx پیدا نشد.');
    }

    $stat = $zip->statName($sheetName);
    if ($stat !== false && (int)$stat['size'] > MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES) {
        $zip->close();
        throw new RuntimeException('فایل xlsx بیش از حد بزرگ است.');
    }

    $shared = xlsx_load_shared_strings($zip);
    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('خواندن محتوای فایل xlsx ممکن نشد.');
    }

    $reader = new XMLReader();
    $reader->xml($sheetXml);

    $rowNo = 0;
    $headerSkipped = false;
    $rows = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
            continue;
        }

        $rowNo++;
        if ($rowNo < $firstRow) {
            continue;
        }
        if ($rowNo > $lastRow) {
            break;
        }

        $cells = xlsx_read_row_cells($reader, $shared);
        $mobile = normalize_msisdn(trim((string)($cells[0] ?? '')));
        $content = trim((string)($cells[1] ?? ''));

        if (!$headerSkipped && $rowNo === 1 && $mobile === null) {
            $headerSkipped = true;
            continue;
        }
        $headerSkipped = true;

        $rows[] = ['mobile' => $mobile, 'content' => $content, 'row_no' => $rowNo];
    }

    $reader->close();
    return $rows;
}

function csv_read_chunks(string $path, int $chunkSize): Generator {
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('باز کردن فایل ممکن نشد.');
    }

    // Strip UTF-8 BOM if present.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $rowNo = 0;
    $buffer = [];
    $headerSkipped = false;

    while (($row = fgetcsv($fh)) !== false) {
        $rowNo++;
        $mobile = normalize_msisdn(trim((string)($row[0] ?? '')));
        $content = trim((string)($row[1] ?? ''));

        if (!$headerSkipped && $rowNo === 1 && $mobile === null) {
            $headerSkipped = true;
            continue;
        }
        $headerSkipped = true;

        $buffer[] = ['mobile' => $mobile ?? '', 'content' => $content, 'row_no' => $rowNo];
        if (count($buffer) >= $chunkSize) {
            yield $buffer;
            $buffer = [];
        }
    }

    fclose($fh);
    if ($buffer !== []) {
        yield $buffer;
    }
}

function xlsx_first_sheet_name(ZipArchive $zip): ?string {
    if ($zip->statName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string)$name) === 1) {
            return (string)$name;
        }
    }
    return null;
}

function xlsx_read_chunks(string $path, int $chunkSize): Generator {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل xlsx معتبر نیست.');
    }

    $sheetName = xlsx_first_sheet_name($zip);
    if ($sheetName === null) {
        $zip->close();
        throw new RuntimeException('صفحه‌ای در فایل xlsx پیدا نشد.');
    }

    $stat = $zip->statName($sheetName);
    if ($stat !== false && (int)$stat['size'] > MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES) {
        $zip->close();
        throw new RuntimeException('فایل xlsx بیش از حد بزرگ است.');
    }

    // Load shared strings (bounded by size guard).
    $shared = xlsx_load_shared_strings($zip);

    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('خواندن محتوای فایل xlsx ممکن نشد.');
    }

    $reader = new XMLReader();
    $reader->xml($sheetXml);

    $rowNo = 0;
    $buffer = [];
    $headerSkipped = false;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
            continue;
        }

        $rowNo++;
        $cells = xlsx_read_row_cells($reader, $shared);
        $mobile = normalize_msisdn(trim((string)($cells[0] ?? '')));
        $content = trim((string)($cells[1] ?? ''));

        if (!$headerSkipped && $rowNo === 1 && $mobile === null) {
            $headerSkipped = true;
            continue;
        }
        $headerSkipped = true;

        $buffer[] = ['mobile' => $mobile ?? '', 'content' => $content, 'row_no' => $rowNo];
        if (count($buffer) >= $chunkSize) {
            yield $buffer;
            $buffer = [];
        }
    }

    $reader->close();
    if ($buffer !== []) {
        yield $buffer;
    }
}

function xlsx_load_shared_strings(ZipArchive $zip): array {
    $stat = $zip->statName('xl/sharedStrings.xml');
    if ($stat === false) {
        return [];
    }
    if ((int)$stat['size'] > IMPORT_MAX_XLSX_SHARED_STRINGS_BYTES) {
        throw new RuntimeException('جدول رشته‌های اشتراکی xlsx بیش از حد بزرگ است.');
    }

    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        return [];
    }

    $shared = [];
    foreach ($sx->si as $si) {
        if (isset($si->t)) {
            $shared[] = (string)$si->t;
        } else {
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string)($run->t ?? '');
            }
            $shared[] = $text;
        }
    }
    return $shared;
}

function xlsx_read_row_cells(XMLReader $reader, array $shared): array {
    $cells = [];
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
            break;
        }
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'c') {
            continue;
        }

        $ref = (string)($reader->getAttribute('r') ?? '');
        $col = xlsx_col_index($ref);
        $type = (string)($reader->getAttribute('t') ?? '');

        // Move to the <v> or <is> child.
        $value = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'c') {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->name === 'v') {
                    $reader->read();
                    $raw = (string)($reader->value ?? '');
                    if ($type === 's') {
                        $value = $shared[(int)$raw] ?? '';
                    } else {
                        $value = $raw;
                    }
                } elseif ($reader->name === 'is' && $type === 'inlineStr') {
                    while ($reader->read() && !($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'is')) {
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 't') {
                            $reader->read();
                            $value .= (string)($reader->value ?? '');
                        }
                    }
                }
            }
        }

        $cells[$col] = trim($value);
    }

    if ($cells === []) {
        return [];
    }
    $maxCol = max(array_keys($cells));
    $out = [];
    for ($c = 0; $c <= $maxCol; $c++) {
        $out[] = $cells[$c] ?? '';
    }
    return $out;
}

// xlsx_col_index() is provided by app/xlsx_reader.php, which this file requires.
