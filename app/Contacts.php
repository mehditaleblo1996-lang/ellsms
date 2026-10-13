<?php
/**
 * ellsms_contacts writes shared by public/contacts.php and the public API (/api/v1/contacts).
 *
 * TD-024: a contact is unique per (user_id, mobile, group_name) —
 * db/migrations/2026_09_28_contacts_unique.sql adds that UNIQUE key. Inserting a row that already
 * exists is a no-op here instead of a second copy or a PDO exception, so re-importing the same
 * list leaves the stored list unchanged.
 */

declare(strict_types=1);

/**
 * @return bool true when a new row was stored, false when this contact already existed.
 */
function contact_insert(PDO $db, int $userId, ?int $organizationId, string $name, string $mobile, string $group): bool {
    // `id = id` touches nothing, so a duplicate reports 0 affected rows (PDO's default
    // MYSQL_ATTR_FOUND_ROWS=false) and a real insert reports 1.
    $st = $db->prepare(
        'INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $st->execute([$userId, $organizationId, $name, $mobile, $group]);
    return $st->rowCount() === 1;
}

/** The id of the existing row a contact_insert() duplicate collided with, or null. */
function contact_find_id(PDO $db, int $userId, string $mobile, string $group): ?int {
    $st = $db->prepare('SELECT id FROM ellsms_contacts WHERE user_id = ? AND mobile = ? AND group_name = ? ORDER BY id LIMIT 1');
    $st->execute([$userId, $mobile, $group]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** True for a MySQL duplicate-key violation (SQLSTATE 23000 / error 1062). */
function contact_is_duplicate_key(PDOException $e): bool {
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}

/* ==========================================================================
   Contact details and the Excel / CSV import (public/contacts.php)
   ========================================================================== */

/** Stored gender codes — the 0/1 coding of the contact-list Excel template. */
const CONTACT_GENDER_LABELS = [0 => 'آقا', 1 => 'خانم'];

/** Data rows one uploaded contact file may hold; the whole file is stored in one request. */
const CONTACT_IMPORT_MAX_ROWS = 100000;

/** Upload size ceiling for a contact file — upload_max_filesize in docker/Dockerfile is 20M too. */
const CONTACT_IMPORT_MAX_BYTES = 20 * 1024 * 1024;

/** How many invalid-row / warning lines an import report keeps as text (the counts stay exact). */
const CONTACT_IMPORT_REPORT_LINES = 200;

/** Column order of the Excel template, used when the first row is not a header. */
const CONTACT_IMPORT_COLUMNS = ['mobile', 'name', 'last_name', 'phone', 'birth_date', 'occasion_date', 'gender', 'email', 'address'];

/** Header titles recognised for each column, compared after contact_header_key(). */
const CONTACT_IMPORT_HEADERS = [
    'mobile'        => ['شماره موبایل', 'موبایل', 'شماره همراه', 'تلفن همراه', 'همراه', 'mobile', 'cellphone', 'msisdn'],
    'name'          => ['نام', 'name', 'first name', 'firstname'],
    'last_name'     => ['نام خانوادگی', 'فامیلی', 'last name', 'lastname', 'family'],
    'phone'         => ['تلفن', 'تلفن ثابت', 'شماره ثابت', 'phone', 'tel'],
    'birth_date'    => ['تاریخ تولد', 'تولد', 'birth date', 'birthdate', 'birthday'],
    'occasion_date' => ['تاریخ مناسبت', 'مناسبت', 'occasion date', 'occasion'],
    'gender'        => ['جنسیت', 'gender', 'sex'],
    'email'         => ['ایمیل', 'پست الکترونیکی', 'email', 'e-mail'],
    'address'       => ['آدرس', 'نشانی', 'address'],
];

/** True once db/migrations/2026_10_17_contact_details.sql is applied; before that only the name is stored. */
function contacts_have_detail_columns(): bool {
    static $has = null;
    if ($has === null) {
        $st = db()->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_contacts' AND column_name = 'last_name'"
        );
        $has = (int)$st->fetchColumn() > 0;
    }
    return $has;
}

/** Comparable form of a header title: Arabic ي/ك folded, spaces/ZWNJ/punctuation dropped, lower case. */
function contact_header_key(string $title): string {
    $t = str_replace(['ي', 'ك'], ['ی', 'ک'], $title);
    $t = preg_replace('/[\s\x{200C}\x{200F}\x{200E}_\-.:]+/u', '', $t) ?? '';
    return mb_strtolower($t);
}

/**
 * A date cell as 'Y-m-d' (Gregorian), or null when it is not a date. Accepts a Jalali date
 * (1362/06/12, 1362-6-12, 13620612), a Gregorian one (1983-09-03) and an Excel date serial
 * (a cell Excel itself formatted as a date), with Persian or Latin digits.
 */
function contact_parse_date(string $raw): ?string {
    $s = trim(from_persian_digits($raw));
    if ($s === '') return null;
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $s) && (float)$s >= 3000 && (float)$s < 80000) {
        // Excel's day 1 is 1900-01-01, counted from 1899-12-30 because of its 1900 leap-year bug.
        // Serials start at 3000 (1908), so a bare year such as 1362 is rejected, not read as 1903.
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$s . ' day')->format('Y-m-d');
    }
    if (preg_match('/^(1[34]\d\d)(\d\d)(\d\d)$/', $s, $m)
        || preg_match('#^(\d{4})\s*[/\-.]\s*(\d{1,2})\s*[/\-.]\s*(\d{1,2})(?:[\sT].*)?$#', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y >= 1200 && $y <= 1500) {
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > jalali_month_length($y, $mo)) return null;
            [$gy, $gm, $gd] = jalali_to_gregorian($y, $mo, $d);
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }
        if ($y >= 1800 && $y <= 2200 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null;
}

/** 0 (آقا) / 1 (خانم) from the template's code or a written-out word; null when unrecognised. */
function contact_parse_gender(string $raw): ?int {
    $s = mb_strtolower(trim(from_persian_digits($raw)));
    return match ($s) {
        '0', 'آقا', 'اقا', 'مرد', 'male', 'm', 'mr' => 0,
        '1', 'خانم', 'زن', 'female', 'f', 'mrs', 'ms' => 1,
        default => null,
    };
}

/**
 * A landline as digits ('02144452586'), or null when it is not a phone number. A 10-digit value
 * gets its leading 0 back: Excel drops it when the column is stored as a number.
 */
function contact_normalize_phone(string $raw): ?string {
    $s = preg_replace('/[^\d+]/', '', from_persian_digits(trim($raw))) ?? '';
    if (str_starts_with($s, '+')) $s = '00' . substr($s, 1);
    if (!preg_match('/^\d{5,15}$/', $s)) return null;
    if (strlen($s) === 10 && $s[0] !== '0') $s = '0' . $s;
    return $s;
}

/**
 * Normalise the optional fields of one contact (raw strings keyed like CONTACT_IMPORT_COLUMNS).
 * A value that cannot be read is dropped and reported, never stored half-parsed.
 *
 * @return array{0: array{name:string,last_name:string,phone:string,gender:?int,birth_date:?string,occasion_date:?string,email:string,address:string}, 1: list<string>}
 */
function contact_normalize_details(array $raw): array {
    $text = static fn(string $k, int $max): string =>
        mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($raw[$k] ?? '')) ?? ''), 0, $max);
    $problems = [];
    $details = [
        'name'          => $text('name', 120),
        'last_name'     => $text('last_name', 120),
        'phone'         => '',
        'gender'        => null,
        'birth_date'    => null,
        'occasion_date' => null,
        'email'         => '',
        'address'       => $text('address', 500),
    ];

    $phone = trim((string)($raw['phone'] ?? ''));
    if ($phone !== '') {
        $n = contact_normalize_phone($phone);
        if ($n === null) {
            $problems[] = 'تلفن «' . $phone . '» نامعتبر است';
        } else {
            $details['phone'] = $n;
        }
    }
    $gender = trim((string)($raw['gender'] ?? ''));
    if ($gender !== '') {
        $g = contact_parse_gender($gender);
        if ($g === null) {
            $problems[] = 'جنسیت «' . $gender . '» نامعتبر است (۰ = آقا، ۱ = خانم)';
        } else {
            $details['gender'] = $g;
        }
    }
    foreach (['birth_date' => 'تاریخ تولد', 'occasion_date' => 'تاریخ مناسبت'] as $k => $label) {
        $v = trim((string)($raw[$k] ?? ''));
        if ($v === '') continue;
        $d = contact_parse_date($v);
        if ($d === null) {
            $problems[] = $label . ' «' . $v . '» نامعتبر است';
        } else {
            $details[$k] = $d;
        }
    }
    $email = trim((string)($raw['email'] ?? ''));
    if ($email !== '') {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190) {
            $details['email'] = $email;
        } else {
            $problems[] = 'ایمیل «' . $email . '» نامعتبر است';
        }
    }
    return [$details, $problems];
}

/**
 * The rows of an uploaded contact file, one at a time, keyed by their row number in the file
 * (1-based) — so a 100,000-row sheet never has to sit in memory at once.
 *
 * The xlsx worksheet is streamed straight out of the zip (zip:// + XMLReader) rather than
 * decompressed into a string first, which is why it is not held to MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES
 * like the whole-file readers: a sheet written with inline strings is ~70MB for 100k rows, and a
 * decompression bomb costs nothing here because contacts_import_rows() stops at the row limit.
 *
 * @return Generator<int, list<string>>
 * @throws RuntimeException with a Persian message when the file cannot be opened
 */
function contacts_file_rows(string $path, string $ext): Generator {
    if ($ext === 'csv') {
        $fh = fopen($path, 'r');
        if (!$fh) throw new RuntimeException('باز کردن فایل ممکن نشد.');
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
        $rowNo = 0;
        try {
            while (($row = fgetcsv($fh)) !== false) {
                yield ++$rowNo => array_map(static fn($v): string => trim((string)$v), $row);
            }
        } finally {
            fclose($fh);
        }
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل xlsx معتبر نیست یا خراب است.');
    }
    $sheetName = xlsx_first_sheet_name($zip);
    $shared = $sheetName !== null ? xlsx_load_shared_strings($zip) : [];
    $zip->close();
    if ($sheetName === null) {
        throw new RuntimeException('صفحه‌ای در فایل xlsx پیدا نشد.');
    }

    $reader = new XMLReader();
    if (!@$reader->open('zip://' . $path . '#' . $sheetName)) {
        throw new RuntimeException('خواندن محتوای فایل xlsx ممکن نشد.');
    }
    $rowNo = 0;
    try {
        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') continue;
            $r = (int)$reader->getAttribute('r');
            $rowNo = $r > $rowNo ? $r : $rowNo + 1;
            yield $rowNo => xlsx_read_row_cells($reader, $shared);
        }
    } finally {
        $reader->close();
    }
}

/**
 * Which column holds which field, from the first non-empty row: columns are found by their header
 * titles (any order, extra columns ignored); a first row that is already data (a mobile in column A)
 * is read in the template's column order.
 *
 * @return array{0: array<string,int>, 1: bool} [field => column index, whether that row is a header]
 * @throws RuntimeException when no mobile column can be found
 */
function contacts_sheet_columns(array $firstRow): array {
    $columns = [];
    foreach ($firstRow as $i => $title) {
        $key = contact_header_key((string)$title);
        foreach (CONTACT_IMPORT_HEADERS as $field => $aliases) {
            if (!isset($columns[$field]) && in_array($key, array_map('contact_header_key', $aliases), true)) {
                $columns[$field] = $i;
                break;
            }
        }
    }
    if (isset($columns['mobile'])) {
        return [$columns, true];
    }
    if ($columns === [] && normalize_msisdn(from_persian_digits((string)($firstRow[0] ?? ''))) !== null) {
        return [array_flip(CONTACT_IMPORT_COLUMNS), false];
    }
    throw new RuntimeException('ستون «شماره موبایل» در ردیف اول فایل پیدا نشد. از فایل نمونه استفاده کنید.');
}

/**
 * Read contacts out of a sheet's rows (row number => cells) and hand them to $onChunk in batches of
 * $chunkSize. A row without a valid mobile is reported and skipped; a mobile repeated within the
 * file keeps its first row; an unreadable optional cell is left empty and reported as a warning.
 * Only the first CONTACT_IMPORT_REPORT_LINES errors and warnings are kept as text; the counts are exact.
 *
 * Throws once the sheet passes CONTACT_IMPORT_MAX_ROWS data rows — run it inside a transaction so
 * the batches already handed over are rolled back and an oversized file stores nothing.
 *
 * @param iterable<int, array> $rows
 * @param callable(list<array{row:int, mobile:string, details:array}>): void $onChunk
 * @return array{rows:int, contacts:int, errors:list<string>, error_count:int, warnings:list<string>, warning_count:int, repeated:int}
 * @throws RuntimeException with a Persian message when the sheet cannot be used at all
 */
function contacts_import_rows(iterable $rows, callable $onChunk, int $chunkSize = 1000): array {
    $out = ['rows' => 0, 'contacts' => 0, 'errors' => [], 'error_count' => 0, 'warnings' => [], 'warning_count' => 0, 'repeated' => 0];
    $columns = null;
    $seen = [];
    $chunk = [];
    $physical = 0;
    foreach ($rows as $rowNo => $row) {
        // Excel's own sheet height; bounds the work a file of nothing but empty rows can cause.
        if (++$physical > 1048576) {
            throw new RuntimeException('فایل بیش از حد بزرگ است.');
        }
        if (!is_array($row) || implode('', array_map('trim', $row)) === '') continue;
        if ($columns === null) {
            [$columns, $isHeader] = contacts_sheet_columns($row);
            if ($isHeader) continue;
        }
        if (++$out['rows'] > CONTACT_IMPORT_MAX_ROWS) {
            throw new RuntimeException('فایل بیش از ' . to_persian_digits(number_format(CONTACT_IMPORT_MAX_ROWS))
                . ' ردیف دارد؛ آن را به چند فایل کوچک‌تر تقسیم کنید. هیچ مخاطبی از این فایل ثبت نشد.');
        }

        $rowLabel = 'ردیف ' . to_persian_digits((string)$rowNo) . ': ';
        $raw = [];
        foreach ($columns as $field => $i) {
            $raw[$field] = (string)($row[$i] ?? '');
        }
        $mobile = normalize_msisdn(from_persian_digits($raw['mobile']));
        if ($mobile === null) {
            if (++$out['error_count'] <= CONTACT_IMPORT_REPORT_LINES) {
                $out['errors'][] = $rowLabel . 'شماره موبایل «' . trim($raw['mobile']) . '» نامعتبر است.';
            }
            continue;
        }
        if (isset($seen[$mobile])) {
            $out['repeated']++;
            continue;
        }
        $seen[$mobile] = true;
        [$details, $problems] = contact_normalize_details($raw);
        foreach ($problems as $p) {
            if (++$out['warning_count'] <= CONTACT_IMPORT_REPORT_LINES) {
                $out['warnings'][] = $rowLabel . $p . '؛ این فیلد خالی ثبت شد.';
            }
        }
        $chunk[] = ['row' => (int)$rowNo, 'mobile' => $mobile, 'details' => $details];
        $out['contacts']++;
        if (count($chunk) >= $chunkSize) {
            $onChunk($chunk);
            $chunk = [];
        }
    }
    if ($columns === null) {
        throw new RuntimeException('فایل خالی است.');
    }
    if ($chunk) {
        $onChunk($chunk);
    }
    return $out;
}

/**
 * contacts_import_rows() over an in-memory list of rows (index 0 = row 1), collecting the contacts.
 *
 * @return array{contacts: list<array{row:int, mobile:string, details:array}>, rows:int, errors: list<string>, error_count:int, warnings: list<string>, warning_count:int, repeated: int}
 */
function contacts_parse_sheet(array $rows): array {
    $contacts = [];
    $numbered = [];
    foreach (array_values($rows) as $i => $row) {
        $numbered[$i + 1] = $row;
    }
    $report = contacts_import_rows($numbered, static function (array $chunk) use (&$contacts): void {
        array_push($contacts, ...$chunk);
    });
    return ['contacts' => $contacts] + $report;
}

/**
 * Group names chosen on a contact form: the ticked existing groups (groups[]), a new one typed
 * in (new_group) and the older single group_name field. No group at all is the '' group.
 *
 * @return list<string>
 */
function contacts_groups_from_request(array $post): array {
    $names = is_array($post['groups'] ?? null) ? $post['groups'] : [];
    $names[] = $post['new_group'] ?? '';
    $names[] = $post['group_name'] ?? '';
    $out = [];
    foreach ($names as $name) {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$name) ?? ''), 0, 80);
        if ($name !== '') $out[$name] = true;
    }
    return $out ? array_map('strval', array_keys($out)) : [''];
}

/**
 * Store contacts in every one of $groups, for one user, inside one transaction that holds the
 * organization's row lock while it counts against the plan's contact limit (as the text import
 * always has).
 *
 * A contact whose mobile is new to a group gets a row there. When the user already has this mobile:
 *  - $replace: the details given (non-empty fields only — an empty cell never wipes stored data)
 *    overwrite every stored row of that mobile, in every group;
 *  - otherwise the stored rows are left exactly as they are.
 *
 * @param list<array{mobile:string, details:array}> $contacts
 * @return array{added:int, updated:int, duplicate:int, over_limit:int, limit:?int}
 *         per contact: added = got at least one new group row; updated = existing details replaced;
 *         duplicate = nothing changed; over_limit = a group row was not added because of the plan limit
 */
function contacts_store(int $userId, ?int $organizationId, array $contacts, array $groups, bool $replace): array {
    $limit = organization_limit((int)$organizationId, Limits::CONTACTS);
    $hasDetails = contacts_have_detail_columns();
    $fields = $hasDetails
        ? ['name', 'last_name', 'phone', 'gender', 'birth_date', 'occasion_date', 'email', 'address']
        : ['name'];

    return db_transaction(function (PDO $db) use ($userId, $organizationId, $contacts, $groups, $replace, $limit, $fields): array {
        $db->prepare('SELECT id FROM ellsms_organizations WHERE id = ? FOR UPDATE')->execute([$organizationId]);
        $current = $limit === null ? 0 : entitlement_current_resource_count((int)$organizationId, Limits::CONTACTS, $db);
        $stats = ['added' => 0, 'updated' => 0, 'duplicate' => 0, 'over_limit' => 0, 'limit' => $limit];

        // Which (mobile, group) rows this user already has, for the whole batch in one query.
        $stored = [];
        $mobiles = array_values(array_unique(array_column($contacts, 'mobile')));
        foreach (array_chunk($mobiles, 1000) as $part) {
            $st = $db->prepare('SELECT mobile, group_name FROM ellsms_contacts WHERE user_id = ? AND mobile IN (' . implode(',', array_fill(0, count($part), '?')) . ')');
            $st->execute([$userId, ...$part]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$m, $grp]) {
                $stored[(string)$m][(string)$grp] = true;
            }
        }

        $insert = $db->prepare(
            'INSERT INTO ellsms_contacts (user_id, organization_id, mobile, group_name, ' . implode(', ', $fields) . ')
             VALUES (?, ?, ?, ?' . str_repeat(', ?', count($fields)) . ')
             ON DUPLICATE KEY UPDATE id = id'
        );

        foreach ($contacts as $c) {
            $details = $c['details'];
            $existed = isset($stored[$c['mobile']]);

            $updated = false;
            if ($existed && $replace) {
                $sets = [];
                $params = [];
                foreach ($fields as $f) {
                    $v = $details[$f] ?? null;
                    if ($v === null || $v === '') continue;
                    $sets[] = "{$f} = ?";
                    $params[] = $v;
                }
                if ($sets) {
                    $params[] = $userId;
                    $params[] = $c['mobile'];
                    $db->prepare('UPDATE ellsms_contacts SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND mobile = ?')->execute($params);
                }
                $updated = true;
            }

            $added = false;
            $overLimit = false;
            foreach ($groups as $group) {
                if (isset($stored[$c['mobile']][$group])) {
                    continue;
                }
                if ($limit !== null && $current >= $limit) {
                    $overLimit = true;
                    continue;
                }
                $values = [$userId, $organizationId, $c['mobile'], $group];
                foreach ($fields as $f) {
                    $values[] = $details[$f] ?? (in_array($f, ['gender', 'birth_date', 'occasion_date'], true) ? null : '');
                }
                $insert->execute($values);
                if ($insert->rowCount() === 1) {
                    $added = true;
                    $current++;
                }
            }

            if ($added) {
                $stats['added']++;
            } elseif ($updated) {
                $stats['updated']++;
            } elseif (!$overLimit) {
                $stats['duplicate']++;
            }
            if ($overLimit) {
                $stats['over_limit']++;
            }
        }
        if ($stats['over_limit'] > 0) {
            // Same signal entitlement_with_resource_slot() raises when it refuses a contact.
            Logger::warning('billing.resource_limit.reached', ['organization_id' => $organizationId, 'limit_key' => Limits::CONTACTS, 'current' => $current, 'limit' => $limit]);
            Metrics::increment('billing.resource_limit.rejected', $stats['over_limit'], ['limit_key' => Limits::CONTACTS]);
        }
        return $stats;
    });
}

/** One Persian sentence for contacts_store()'s result, and the flash type it deserves. */
function contacts_store_summary(array $stats): array {
    $fa = static fn(int $n): string => to_persian_digits(number_format($n));
    $parts = [];
    if ($stats['added'] > 0) $parts[] = $fa($stats['added']) . ' مخاطب ثبت شد';
    if ($stats['updated'] > 0) $parts[] = 'اطلاعات ' . $fa($stats['updated']) . ' مخاطب موجود به‌روزرسانی شد';
    if ($stats['duplicate'] > 0) $parts[] = $fa($stats['duplicate']) . ' مخاطب از قبل در همین گروه‌ها بود و تغییری نکرد';
    if ($stats['over_limit'] > 0) {
        $parts[] = $fa($stats['over_limit']) . ' مخاطب به دلیل رسیدن به سقف پلن (' . $fa((int)$stats['limit'])
            . ' مخاطب) در همه‌ی گروه‌ها ثبت نشد؛ برای افزایش ظرفیت، پلن خود را ارتقا دهید';
    }
    if (!$parts) return ['info', 'تغییری ایجاد نشد.'];
    $type = $stats['over_limit'] > 0 ? 'error' : ($stats['added'] + $stats['updated'] > 0 ? 'success' : 'info');
    return [$type, implode('؛ ', $parts) . '.'];
}
