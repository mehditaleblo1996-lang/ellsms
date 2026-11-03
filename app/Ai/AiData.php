<?php
/**
 * ELLSMS — data tables of the AI secretary (جدول‌های داده‌ی منشی), phase 4 (docs/ai-data.md).
 *
 * A customer keeps its own tables — products, orders, appointments, anything — with columns it
 * names itself, fills them by hand or from Excel/CSV (replace, append, or update by a key column),
 * and gives a line's assistant the tables it may look into (public/ai-data.php, public/ai-secretary.php).
 *
 * LOOKUP, two ways, one search underneath (ai_data_search()):
 *   - tool calls: a model flagged supports_tools gets a `lookup_records` tool and asks for what it
 *     needs ("order 4512"), possibly several times, before answering;
 *   - automatic search: for any other model (Hermes on a server without a tool parser, Ollama …),
 *     the customer's message (and the previous one) is searched and the best rows go into the prompt.
 *
 * SEARCH. Values and queries are normalized the same way (ai_data_normalize(): Arabic ي/ك to ی/ک,
 * Persian/Arabic digits to Latin, diacritics and punctuation dropped, lower case), so «۴۵۱۲» finds
 * 4512 and «كيف» finds «کیف». Candidate rows come from LIKE on search_text; each is scored in PHP
 * (an exact key match first, then whole-word matches, numbers weighing double).
 *
 * PRIVACY. A table with a phone column only ever shows a sender the rows whose phone is its own
 * number — nobody can read someone else's order by guessing its code. That restriction is applied
 * in SQL, before scoring, for both lookup ways.
 *
 * Schema: db/migrations/2026_11_06_ai_datasets.sql.
 */

declare(strict_types=1);

const AI_DATA_MAX_ROWS = 50000;
const AI_DATA_MAX_COLUMNS = 30;
const AI_DATA_MAX_VALUE_CHARS = 1000;
const AI_DATA_COLUMN_TYPES = ['text' => 'متن', 'number' => 'عدد', 'date' => 'تاریخ', 'phone' => 'شماره‌ی موبایل'];
/** Rows the model is shown per lookup. */
const AI_DATA_RESULT_LIMIT = 8;
/** Short Persian words that never identify a record. */
const AI_DATA_STOPWORDS = ['و', 'در', 'به', 'از', 'که', 'این', 'آن', 'را', 'با', 'است', 'برای', 'تا', 'یک', 'من', 'ما', 'شما',
    'چه', 'چی', 'کی', 'کجا', 'چطور', 'چند', 'هست', 'هستش', 'میشه', 'می', 'های', 'ها', 'سلام', 'لطفا', 'ممنون', 'مرسی', 'آیا',
    'یا', 'هم', 'بله', 'نه', 'the', 'is', 'a', 'an', 'of', 'and', 'or', 'to', 'my', 'me', 'hi', 'hello', 'please'];

/**
 * Ready-made tables: key => [label, name, description, columns [[label, type], ...], key label|null, phone label|null].
 */
const AI_DATA_TEMPLATES = [
    'shop_products' => ['فروشگاه — محصولات', 'محصولات', 'فهرست کالاها با قیمت و موجودی',
        [['نام کالا', 'text'], ['کد کالا', 'text'], ['قیمت (تومان)', 'number'], ['موجودی', 'number'], ['توضیحات', 'text']], 'کد کالا', null],
    'shop_orders' => ['فروشگاه — سفارش‌ها', 'سفارش‌ها', 'وضعیت سفارش‌های مشتریان؛ هر مشتری فقط سفارش‌های خودش را می‌بیند',
        [['کد سفارش', 'text'], ['موبایل مشتری', 'phone'], ['وضعیت', 'text'], ['تاریخ ارسال', 'date'], ['کد رهگیری پستی', 'text']], 'کد سفارش', 'موبایل مشتری'],
    'clinic_appointments' => ['کلینیک — نوبت‌ها', 'نوبت‌ها', 'نوبت‌های رزروشده؛ هر بیمار فقط نوبت‌های خودش را می‌بیند',
        [['کد نوبت', 'text'], ['موبایل بیمار', 'phone'], ['نام بیمار', 'text'], ['تاریخ', 'date'], ['ساعت', 'text'], ['پزشک', 'text']], 'کد نوبت', 'موبایل بیمار'],
    'clinic_services' => ['کلینیک — خدمات و تعرفه‌ها', 'خدمات', 'خدمات کلینیک با هزینه',
        [['خدمت', 'text'], ['هزینه (تومان)', 'number'], ['توضیح', 'text']], 'خدمت', null],
    'services_requests' => ['خدمات — درخواست‌ها', 'درخواست‌ها', 'وضعیت درخواست‌ها با کد پیگیری؛ هر مشتری فقط درخواست‌های خودش را می‌بیند',
        [['کد پیگیری', 'text'], ['موبایل مشتری', 'phone'], ['وضعیت', 'text'], ['آخرین به‌روزرسانی', 'date'], ['توضیح', 'text']], 'کد پیگیری', 'موبایل مشتری'],
];

function ai_data_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) return $available = $set;
    if ($available === null) {
        $available = false;
        if (ai_secretary_available()) {
            try {
                db()->query('SELECT 1 FROM ellsms_ai_datasets LIMIT 0');
                db()->query('SELECT 1 FROM ellsms_ai_assistant_datasets LIMIT 0');
                db()->query('SELECT 1 FROM ellsms_ai_conversations LIMIT 0');
                db()->query('SELECT handoff_enabled FROM ellsms_ai_assistants LIMIT 0');
                $available = true;
            } catch (PDOException) {
                $available = false;
            }
        }
    }
    return $available;
}

/* ==========================================================================
   Pure helpers (tests/Unit/AiDataTest.php)
   ========================================================================== */

/** Pure: text normalized for matching. */
function ai_data_normalize(string $text): string {
    $text = strtr($text, [
        'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'ٱ' => 'ا',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        "\u{200C}" => ' ', "\u{200D}" => '', 'ـ' => '',
    ]);
    $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text;   // diacritics
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** Pure: the words of a message worth searching for (no stopwords; numbers of 3+ digits; words of 2+ letters). */
function ai_data_query_terms(string $query, int $max = 8): array {
    $terms = [];
    foreach (explode(' ', ai_data_normalize($query)) as $word) {
        if ($word === '' || in_array($word, AI_DATA_STOPWORDS, true)) continue;
        if (ctype_digit($word) ? strlen($word) < 3 : mb_strlen($word) < 2) continue;
        $terms[$word] = true;
    }
    // Numbers first: an order or tracking code is the strongest clue in a message.
    // array_keys() hands numeric keys back as ints («4512» -> 4512); strval() undoes that.
    $list = array_map('strval', array_keys($terms));
    usort($list, static fn(string $a, string $b): int => (int)ctype_digit($b) <=> (int)ctype_digit($a));
    return array_slice($list, 0, $max);
}

/** Pure: how well a row matches the terms (0 = not at all). */
function ai_data_score(string $searchText, array $terms, ?string $rowKey): int {
    $score = 0;
    $key = $rowKey !== null ? ai_data_normalize($rowKey) : null;
    $words = array_flip(explode(' ', $searchText));
    foreach ($terms as $term) {
        $weight = ctype_digit($term) ? 2 : 1;
        if ($key !== null && $key === $term) {
            $score += 10 * $weight;
        } elseif (isset($words[$term])) {
            $score += 3 * $weight;
        } elseif (str_contains($searchText, $term)) {
            $score += $weight;
        }
    }
    return $score;
}

/**
 * Pure: column definitions from the form ([['key'?, 'label', 'type', 'default'], ...]) — keys kept for
 * existing columns, new ones numbered after the highest; empty labels dropped.
 * @return array{0: list<array{key:string,label:string,type:string,default:string}>, 1: ?string}
 */
function ai_data_columns_from_form(array $posted): array {
    $out = [];
    $used = [];
    $next = 1;
    foreach ($posted as $col) {
        if (preg_match('/^c(\d+)$/', (string)($col['key'] ?? ''), $m) === 1) $next = max($next, (int)$m[1] + 1);
    }
    foreach ($posted as $col) {
        $label = trim(preg_replace('/\s+/u', ' ', (string)($col['label'] ?? '')) ?? '');
        if ($label === '') continue;
        if (mb_strlen($label) > 60) return [[], 'نام ستون حداکثر ۶۰ نویسه است.'];
        $norm = ai_data_normalize($label);
        if (isset($used[$norm])) return [[], 'نام ستون «' . $label . '» تکراری است.'];
        $used[$norm] = true;
        $key = (string)($col['key'] ?? '');
        if (preg_match('/^c\d+$/', $key) !== 1) $key = 'c' . $next++;
        $type = array_key_exists((string)($col['type'] ?? ''), AI_DATA_COLUMN_TYPES) ? (string)$col['type'] : 'text';
        $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => mb_substr(trim((string)($col['default'] ?? '')), 0, 200)];
    }
    if ($out === []) return [[], 'حداقل یک ستون تعریف کنید.'];
    if (count($out) > AI_DATA_MAX_COLUMNS) return [[], 'حداکثر ' . AI_DATA_MAX_COLUMNS . ' ستون مجاز است.'];
    return [$out, null];
}

/** Pure: a phone value as 98…, or null. */
function ai_data_phone(string $value): ?string {
    return normalize_msisdn(strtr(ai_data_normalize($value), [' ' => '']));
}

/**
 * Pure: one row ready to store, from values keyed by column key. Empty cells take the column's
 * default; number columns accept Persian digits and thousands separators.
 * @return array{data: array<string,string>, row_key: ?string, phone: ?string, search_text: string}
 */
function ai_data_row_build(array $dataset, array $values): array {
    $data = [];
    foreach ($dataset['columns'] as $col) {
        $v = trim((string)($values[$col['key']] ?? ''));
        if ($v === '') $v = (string)$col['default'];
        if ($col['type'] === 'number' && $v !== '') {
            $n = str_replace([',', '٬', ' '], '', strtr($v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']));
            if (is_numeric($n)) $v = $n;
        }
        $data[$col['key']] = mb_substr($v, 0, AI_DATA_MAX_VALUE_CHARS);
    }
    $key = $dataset['key_column'] !== null ? trim($data[$dataset['key_column']] ?? '') : '';
    $phone = $dataset['phone_column'] !== null ? ai_data_phone($data[$dataset['phone_column']] ?? '') : null;
    return [
        'data' => $data,
        'row_key' => $key !== '' ? mb_substr(ai_data_normalize($key), 0, 191) : null,
        'phone' => $phone,
        'search_text' => ai_data_normalize(implode(' ', $data)),
    ];
}

/** Pure: rows as text for the model — "### name (description)" then one line per row. */
function ai_data_format_results(array $results): string {
    if ($results === []) return '';
    $out = [];
    $current = null;
    foreach ($results as $r) {
        if ($current !== $r['dataset']['id']) {
            $current = $r['dataset']['id'];
            $desc = trim((string)$r['dataset']['description']);
            $out[] = '### ' . $r['dataset']['name'] . ($desc !== '' ? ' (' . $desc . ')' : '');
        }
        $cells = [];
        foreach ($r['dataset']['columns'] as $col) {
            $v = trim((string)($r['data'][$col['key']] ?? ''));
            if ($v !== '') $cells[] = $col['label'] . ': ' . $v;
        }
        $out[] = '- ' . implode(' | ', $cells);
    }
    return implode("\n", $out);
}

/** Pure: the `lookup_records` tool for these tables. */
function ai_data_tool(array $datasets): array {
    $names = array_values(array_map(static fn(array $d): string => $d['name'], $datasets));
    $lines = [];
    foreach ($datasets as $d) {
        $lines[] = '«' . $d['name'] . '»' . (trim($d['description']) !== '' ? ' — ' . $d['description'] : '')
            . ' — columns: ' . implode('، ', array_column($d['columns'], 'label'))
            . ($d['phone_column'] !== null ? ' (only the customer\'s own rows are returned)' : '');
    }
    return [
        'name' => 'lookup_records',
        'description' => "Search the business's own records. Call it whenever the answer depends on a product, order, appointment, price, stock or status. Tables:\n" . implode("\n", $lines),
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'enum' => $names, 'description' => 'Which table to search'],
                'query' => ['type' => 'string', 'description' => 'What to look for: a code, a name, or keywords, as the customer wrote them'],
            ],
            'required' => ['table', 'query'],
        ],
    ];
}

/* ==========================================================================
   Tables
   ========================================================================== */

function ai_dataset_normalize(array $row): array {
    $row['id'] = (int)$row['id'];
    $row['user_id'] = (int)$row['user_id'];
    $row['columns'] = json_decode((string)$row['columns_json'], true) ?: [];
    $row['key_column'] = $row['key_column'] !== null && $row['key_column'] !== '' ? (string)$row['key_column'] : null;
    $row['phone_column'] = $row['phone_column'] !== null && $row['phone_column'] !== '' ? (string)$row['phone_column'] : null;
    return $row;
}

/** @return list<array> the user's tables (null = every table, for an admin) */
function ai_datasets_for(?int $userId): array {
    $st = $userId === null
        ? db()->query('SELECT * FROM ellsms_ai_datasets ORDER BY user_id, name')
        : (function () use ($userId) { $s = db()->prepare('SELECT * FROM ellsms_ai_datasets WHERE user_id = ? ORDER BY name'); $s->execute([$userId]); return $s; })();
    return array_map('ai_dataset_normalize', $st->fetchAll());
}

/** A table of $userId (any table when $userId is null — a platform admin). */
function ai_dataset_find(int $id, ?int $userId): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_ai_datasets WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : ''));
    $st->execute($userId !== null ? [$id, $userId] : [$id]);
    $row = $st->fetch();
    return $row ? ai_dataset_normalize($row) : null;
}

/**
 * Validates and saves a table definition; returns its id. $columns from ai_data_columns_from_form();
 * $keyLabel / $phoneLabel name a column (or '' for none). Existing rows are re-indexed when the key
 * or phone column changed.
 */
function ai_dataset_save(int $userId, ?int $id, string $name, string $description, array $columns, string $keyColumn, string $phoneColumn, string $template, int $actorId): int {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) throw new AppException('نام جدول باید ۱ تا ۸۰ نویسه باشد.');
    $keys = array_column($columns, 'key');
    $keyColumn = in_array($keyColumn, $keys, true) ? $keyColumn : null;
    $phoneColumn = in_array($phoneColumn, $keys, true) ? $phoneColumn : null;
    $existing = $id !== null ? ai_dataset_find($id, $userId) : null;
    if ($id !== null && $existing === null) throw new AppException('جدول پیدا نشد.');
    $dup = db()->prepare('SELECT id FROM ellsms_ai_datasets WHERE user_id = ? AND name = ?' . ($id !== null ? ' AND id <> ?' : ''));
    $dup->execute($id !== null ? [$userId, $name, $id] : [$userId, $name]);
    if ($dup->fetchColumn()) throw new AppException('جدولی با این نام دارید.');
    $params = [$name, mb_substr(trim($description), 0, 500), json_encode($columns, JSON_UNESCAPED_UNICODE), $keyColumn, $phoneColumn];
    if ($existing === null) {
        db()->prepare('INSERT INTO ellsms_ai_datasets (name, description, columns_json, key_column, phone_column, user_id, template) VALUES (?,?,?,?,?,?,?)')
            ->execute(array_merge($params, [$userId, $template]));
        $id = (int)db()->lastInsertId();
    } else {
        db()->prepare('UPDATE ellsms_ai_datasets SET name = ?, description = ?, columns_json = ?, key_column = ?, phone_column = ? WHERE id = ?')
            ->execute(array_merge($params, [$id]));
        ai_dataset_reindex((int)$id);
    }
    audit($actorId, 'ai.dataset.save', json_encode(['dataset_id' => $id, 'user_id' => $userId, 'columns' => count($columns)], JSON_UNESCAPED_UNICODE) ?: '');
    return (int)$id;
}

/** Creates a table from a template. */
function ai_dataset_from_template(int $userId, string $template, int $actorId): int {
    $t = AI_DATA_TEMPLATES[$template] ?? throw new AppException('الگو پیدا نشد.');
    $columns = [];
    $key = $phone = '';
    foreach ($t[3] as $i => [$label, $type]) {
        $columns[] = ['key' => 'c' . ($i + 1), 'label' => $label, 'type' => $type, 'default' => ''];
        if ($label === $t[4]) $key = 'c' . ($i + 1);
        if ($label === $t[5]) $phone = 'c' . ($i + 1);
    }
    $name = $t[1];
    for ($n = 2; ai_dataset_name_taken($userId, $name); $n++) $name = $t[1] . ' ' . $n;
    return ai_dataset_save($userId, null, $name, $t[2], $columns, $key, $phone, $template, $actorId);
}

function ai_dataset_name_taken(int $userId, string $name): bool {
    $st = db()->prepare('SELECT 1 FROM ellsms_ai_datasets WHERE user_id = ? AND name = ?');
    $st->execute([$userId, $name]);
    return (bool)$st->fetchColumn();
}

function ai_dataset_delete(int $id, int $userId, int $actorId): void {
    db()->prepare('DELETE FROM ellsms_ai_datasets WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    audit($actorId, 'ai.dataset.delete', json_encode(['dataset_id' => $id]) ?: '');
}

/** Rebuilds row_key / phone / search_text of every row (after the column definition changed). */
function ai_dataset_reindex(int $datasetId): void {
    $dataset = ai_dataset_find($datasetId, null);
    if ($dataset === null) return;
    $lastId = 0;
    $update = db()->prepare('UPDATE ellsms_ai_dataset_rows SET data_json = ?, row_key = ?, phone = ?, search_text = ? WHERE id = ?');
    $seenKeys = [];
    do {
        $st = db()->prepare('SELECT id, data_json FROM ellsms_ai_dataset_rows WHERE dataset_id = ? AND id > ? ORDER BY id LIMIT 1000');
        $st->execute([$datasetId, $lastId]);
        $rows = $st->fetchAll();
        foreach ($rows as $r) {
            $lastId = (int)$r['id'];
            $built = ai_data_row_build($dataset, json_decode((string)$r['data_json'], true) ?: []);
            // A new key column may hold duplicates: the later rows keep no key rather than fail.
            if ($built['row_key'] !== null && isset($seenKeys[$built['row_key']])) $built['row_key'] = null;
            if ($built['row_key'] !== null) $seenKeys[$built['row_key']] = true;
            $update->execute([json_encode($built['data'], JSON_UNESCAPED_UNICODE), $built['row_key'], $built['phone'], $built['search_text'], $lastId]);
        }
    } while (count($rows) === 1000);
    ai_dataset_count($datasetId);
}

function ai_dataset_count(int $datasetId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_ai_dataset_rows WHERE dataset_id = ?');
    $st->execute([$datasetId]);
    $n = (int)$st->fetchColumn();
    db()->prepare('UPDATE ellsms_ai_datasets SET row_count = ? WHERE id = ?')->execute([$n, $datasetId]);
    return $n;
}

/* ==========================================================================
   Rows
   ========================================================================== */

/** Adds or edits one row; with a key column, a new row whose key exists updates that row. */
function ai_data_row_save(array $dataset, ?int $rowId, array $values): int {
    $built = ai_data_row_build($dataset, $values);
    if (implode('', $built['data']) === '') throw new AppException('ردیف خالی است.');
    $json = json_encode($built['data'], JSON_UNESCAPED_UNICODE);
    try {
        if ($rowId !== null) {
            $st = db()->prepare('UPDATE ellsms_ai_dataset_rows SET data_json = ?, row_key = ?, phone = ?, search_text = ? WHERE id = ? AND dataset_id = ?');
            $st->execute([$json, $built['row_key'], $built['phone'], $built['search_text'], $rowId, $dataset['id']]);
            return $rowId;
        }
        if ($dataset['row_count'] >= AI_DATA_MAX_ROWS) throw new AppException('سقف ' . number_format(AI_DATA_MAX_ROWS) . ' ردیف برای هر جدول پر شده است.');
        db()->prepare('INSERT INTO ellsms_ai_dataset_rows (dataset_id, row_key, phone, data_json, search_text) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE phone = VALUES(phone), data_json = VALUES(data_json), search_text = VALUES(search_text)')
            ->execute([$dataset['id'], $built['row_key'], $built['phone'], $json, $built['search_text']]);
        $id = (int)db()->lastInsertId();
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) throw new AppException('ردیف دیگری با همین مقدار ستون کلید وجود دارد.');
        throw $e;
    }
    ai_dataset_count((int)$dataset['id']);
    return $id;
}

function ai_data_row_delete(array $dataset, int $rowId): void {
    db()->prepare('DELETE FROM ellsms_ai_dataset_rows WHERE id = ? AND dataset_id = ?')->execute([$rowId, $dataset['id']]);
    ai_dataset_count((int)$dataset['id']);
}

/**
 * Pure: which column each file header fills — header index => column key, matched by normalized
 * label. Unknown headers are ignored.
 */
function ai_data_map_headers(array $dataset, array $header): array {
    $byLabel = [];
    foreach ($dataset['columns'] as $col) $byLabel[ai_data_normalize($col['label'])] = $col['key'];
    $map = [];
    foreach ($header as $i => $h) {
        $key = $byLabel[ai_data_normalize((string)$h)] ?? null;
        if ($key !== null && !in_array($key, $map, true)) $map[$i] = $key;
    }
    return $map;
}

/**
 * Imports spreadsheet rows (first row = header). $mode: 'append' adds every row, 'upsert' updates
 * rows with the same key and adds the rest (needs a key column), 'replace' empties the table first.
 * @return array{added:int, updated:int, skipped:int}
 */
function ai_data_import(array $dataset, array $rows, string $mode, int $actorId): array {
    if ($rows === []) throw new AppException('فایل خالی است.');
    $header = array_shift($rows);
    $map = ai_data_map_headers($dataset, $header);
    if ($map === []) throw new AppException('هیچ‌کدام از سرستون‌های فایل با نام ستون‌های جدول یکی نیست. ردیف اول فایل باید نام ستون‌ها باشد: ' . implode('، ', array_column($dataset['columns'], 'label')));
    if ($mode === 'upsert' && $dataset['key_column'] === null) throw new AppException('برای به‌روزرسانی بر اساس کلید، ابتدا ستون کلید جدول را تعیین کنید.');
    if (count($rows) > AI_DATA_MAX_ROWS) throw new AppException('حداکثر ' . number_format(AI_DATA_MAX_ROWS) . ' ردیف در هر جدول.');
    $counts = ['added' => 0, 'updated' => 0, 'skipped' => 0];
    db_transaction(function (PDO $db) use ($dataset, $rows, $mode, $map, &$counts): void {
        if ($mode === 'replace') {
            $db->prepare('DELETE FROM ellsms_ai_dataset_rows WHERE dataset_id = ?')->execute([$dataset['id']]);
        }
        $sql = 'INSERT INTO ellsms_ai_dataset_rows (dataset_id, row_key, phone, data_json, search_text) VALUES (?,?,?,?,?)';
        if ($mode !== 'append') {
            $sql .= ' ON DUPLICATE KEY UPDATE phone = VALUES(phone), data_json = VALUES(data_json), search_text = VALUES(search_text)';
        }
        $ins = $db->prepare($sql);
        foreach ($rows as $cells) {
            $values = [];
            foreach ($map as $i => $key) $values[$key] = (string)($cells[$i] ?? '');
            if (trim(implode('', $values)) === '') { $counts['skipped']++; continue; }
            $built = ai_data_row_build($dataset, $values);
            try {
                $ins->execute([$dataset['id'], $mode === 'append' && $built['row_key'] === null ? null : $built['row_key'], $built['phone'],
                    json_encode($built['data'], JSON_UNESCAPED_UNICODE), $built['search_text']]);
                $ins->rowCount() === 2 ? $counts['updated']++ : $counts['added']++;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $counts['skipped']++; // 'append' and a key that already exists
            }
        }
    });
    $total = ai_dataset_count((int)$dataset['id']);
    if ($total > AI_DATA_MAX_ROWS) {
        Logger::warning('ai.dataset.over_limit', ['dataset_id' => $dataset['id'], 'rows' => $total]);
    }
    audit($actorId, 'ai.dataset.import', json_encode(['dataset_id' => $dataset['id'], 'mode' => $mode] + $counts) ?: '');
    return $counts;
}

/**
 * Creates a table from a spreadsheet's header row and imports its rows.
 * @return array{id:int, added:int, updated:int, skipped:int}
 */
function ai_dataset_from_file(int $userId, string $name, array $rows, int $actorId): array {
    if ($rows === [] || array_filter($rows[0], static fn($h): bool => trim((string)$h) !== '') === []) {
        throw new AppException('ردیف اول فایل باید نام ستون‌ها باشد.');
    }
    [$columns, $error] = ai_data_columns_from_form(array_map(static fn($h): array => ['label' => (string)$h], $rows[0]));
    if ($error !== null) throw new AppException($error);
    $id = ai_dataset_save($userId, null, $name !== '' ? $name : 'جدول ' . jdate(gmdate('Y-m-d H:i:s'), false), '', $columns, '', '', 'file', $actorId);
    return ['id' => $id] + ai_data_import(ai_dataset_find($id, $userId), $rows, 'append', $actorId);
}

/** @return array{rows: list<array>, total:int} one page of a table's rows, optionally filtered. */
function ai_data_rows_page(array $dataset, string $query, int $page, int $perPage = 50): array {
    $where = 'dataset_id = ?';
    $params = [$dataset['id']];
    foreach (ai_data_query_terms($query, 5) as $term) {
        $where .= ' AND search_text LIKE ?';
        $params[] = '%' . $term . '%';
    }
    $count = db()->prepare("SELECT COUNT(*) FROM ellsms_ai_dataset_rows WHERE $where");
    $count->execute($params);
    $st = db()->prepare("SELECT id, data_json FROM ellsms_ai_dataset_rows WHERE $where ORDER BY id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
    $st->execute($params);
    return ['rows' => array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'data' => json_decode((string)$r['data_json'], true) ?: []], $st->fetchAll()),
            'total' => (int)$count->fetchColumn()];
}

/* ==========================================================================
   Lookup
   ========================================================================== */

/**
 * The best rows of $datasets for $query, as seen by $sender (restricted tables only show its own
 * rows). In a restricted table, a query with no usable terms — or whose terms match none of the
 * sender's rows — gives the sender's latest rows: «سفارشم کجاست؟» needs no code when the table knows
 * the sender.
 * @return list<array{dataset:array, data:array, score:int}>
 */
function ai_data_search(array $datasets, string $query, ?string $sender, int $limit = AI_DATA_RESULT_LIMIT): array {
    $terms = ai_data_query_terms($query);
    $phone = $sender !== null ? ai_data_phone($sender) : null;
    $found = [];
    foreach ($datasets as $d) {
        $where = 'dataset_id = ?';
        $params = [$d['id']];
        if ($d['phone_column'] !== null) {
            if ($phone === null) continue;
            $where .= ' AND phone = ?';
            $params[] = $phone;
        }
        if ($terms !== []) {
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($terms), 'search_text LIKE ?')) . ')';
            foreach ($terms as $t) $params[] = '%' . $t . '%';
        } elseif ($d['phone_column'] === null) {
            continue;
        }
        $st = db()->prepare("SELECT row_key, data_json, search_text FROM ellsms_ai_dataset_rows WHERE $where ORDER BY id DESC LIMIT 300");
        $st->execute($params);
        $matched = 0;
        foreach ($st->fetchAll() as $r) {
            $score = $terms === [] ? 1 : ai_data_score((string)$r['search_text'], $terms, $r['row_key']);
            if ($score <= 0) continue;
            $matched++;
            $found[] = ['dataset' => $d, 'data' => json_decode((string)$r['data_json'], true) ?: [], 'score' => $score];
        }
        // «نوبت من کیه؟» names no value of the row, but in a table restricted to the sender the
        // sender's own latest rows are what it asks about — and they are all it may see anyway.
        if ($matched === 0 && $terms !== [] && $d['phone_column'] !== null) {
            $latest = db()->prepare('SELECT data_json FROM ellsms_ai_dataset_rows WHERE dataset_id = ? AND phone = ? ORDER BY id DESC LIMIT 3');
            $latest->execute([$d['id'], $phone]);
            foreach ($latest->fetchAll() as $r) {
                $found[] = ['dataset' => $d, 'data' => json_decode((string)$r['data_json'], true) ?: [], 'score' => 1];
            }
        }
    }
    usort($found, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $top = array_slice($found, 0, $limit);
    // Keep each table's rows together for the prompt.
    usort($top, static fn(array $a, array $b): int => [$a['dataset']['id'], -$a['score']] <=> [$b['dataset']['id'], -$b['score']]);
    return $top;
}

/** Runs a `lookup_records` tool call; returns the text handed back to the model. */
function ai_data_tool_run(array $datasets, array $arguments, ?string $sender): array {
    $table = trim((string)($arguments['table'] ?? ''));
    $query = trim((string)($arguments['query'] ?? ''));
    $selected = array_values(array_filter($datasets, static fn(array $d): bool => $d['name'] === $table));
    if ($selected === []) $selected = $datasets;
    $results = ai_data_search($selected, $query, $sender);
    return ['text' => $results === [] ? 'No matching record was found.' : ai_data_format_results($results), 'count' => count($results)];
}

/* ==========================================================================
   Assistants <-> tables
   ========================================================================== */

/** @return list<array> the tables a line's assistant may look into */
function ai_assistant_datasets(int $assistantId): array {
    if (!ai_data_available()) return [];
    $st = db()->prepare('SELECT d.* FROM ellsms_ai_assistant_datasets a JOIN ellsms_ai_datasets d ON d.id = a.dataset_id
                         JOIN ellsms_ai_assistants s ON s.id = a.assistant_id AND s.user_id = d.user_id
                         WHERE a.assistant_id = ? ORDER BY d.name');
    $st->execute([$assistantId]);
    return array_map('ai_dataset_normalize', $st->fetchAll());
}

/** Sets an assistant's tables; only tables of the assistant's owner are accepted. */
function ai_assistant_set_datasets(int $assistantId, int $ownerId, array $datasetIds): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $datasetIds), static fn(int $i): bool => $i > 0)));
    db_transaction(function (PDO $db) use ($assistantId, $ownerId, $ids): void {
        $db->prepare('DELETE FROM ellsms_ai_assistant_datasets WHERE assistant_id = ?')->execute([$assistantId]);
        $ins = $db->prepare('INSERT INTO ellsms_ai_assistant_datasets (assistant_id, dataset_id)
                             SELECT ?, id FROM ellsms_ai_datasets WHERE id = ? AND user_id = ?');
        foreach ($ids as $id) $ins->execute([$assistantId, $id, $ownerId]);
    });
}
