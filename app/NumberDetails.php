<?php
/**
 * Number details (/numbers.php → «ویرایش شماره», db/migrations/2026_10_28_number_details.sql):
 * status, expiry, priority, bandwidth, confidential sending, inbound-cost and the commitment letter.
 *
 * Only status and expiry change behaviour: a line that is not 'active', or whose expires_on day
 * (Tehran) has passed, cannot be SENT from — can_use_originator() refuses it, the send pickers drop
 * it (app/authorization.php) and dispatch_message_raw() names the reason. Inbound SMS, the inbox,
 * delivery reports and URL forwarding keep working, so a suspended line loses nothing it receives.
 * The other fields are recorded and shown only.
 *
 * Before the migration is applied every line reads as sendable, exactly as before.
 */

declare(strict_types=1);

const NUMBER_STATUSES = [
    'active'      => 'فعال',
    'inactive'    => 'غیرفعال',
    'blocked'     => 'مسدود',
    'deleted'     => 'حذف',
    'transferred' => 'منتقل شده',
    'reserved'    => 'رزرو شده',
];

/** Seconds a long-running worker keeps the unsendable-line list before reading it again. */
const NUMBER_UNSENDABLE_TTL = 30;

define('NUMBER_COMMITMENT_DIR', APP_ROOT . '/storage/number-commitments');
const NUMBER_COMMITMENT_MAX_BYTES = 8 * 1024 * 1024;

function number_status_label(string $status): string { return NUMBER_STATUSES[$status] ?? $status; }

/** True once the migration has added the detail columns. */
function number_details_ready(): bool {
    if (array_key_exists('__number_details_ready', $GLOBALS)) return $GLOBALS['__number_details_ready'];
    try {
        db()->query('SELECT status, expires_on FROM ellsms_numbers LIMIT 0');
        return $GLOBALS['__number_details_ready'] = true;
    } catch (PDOException) {
        return $GLOBALS['__number_details_ready'] = false;
    }
}

/** Today's date in Tehran ('Y-m-d') — the day expires_on is compared against. */
function number_today(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
}

/** True when a line with this expiry date can no longer send today. */
function number_is_expired(?string $expiresOn): bool {
    return $expiresOn !== null && $expiresOn !== '' && $expiresOn < number_today();
}

/** Why a line with this status/expiry cannot send, or null when it can. */
function number_unsendable_reason_for(string $status, ?string $expiresOn): ?string {
    if ($status !== 'active') return 'وضعیت خط «' . number_status_label($status) . '» است';
    if (number_is_expired($expiresOn)) return 'تاریخ انقضای خط (' . jdate($expiresOn, false) . ') گذشته است';
    return null;
}

/**
 * Every registered line that cannot send right now, as number => reason. Cached for
 * NUMBER_UNSENDABLE_TTL seconds, since workers ask on every message. The system line is never
 * listed: the platform's own login codes and notices must not be switched off from this page.
 * $fresh skips the cache (tests, or right after a change in the same process).
 *
 * @return array<string,string>
 */
function number_unsendable_map(bool $fresh = false): array {
    static $cache = null, $at = 0, $day = '';
    $today = number_today();
    if (!$fresh && $cache !== null && time() - $at < NUMBER_UNSENDABLE_TTL && $day === $today) return $cache;
    $map = [];
    if (number_details_ready()) {
        try {
            $st = db()->prepare("SELECT number, status, expires_on FROM ellsms_numbers WHERE status <> 'active' OR expires_on < ?");
            $st->execute([$today]);
            foreach ($st as $row) {
                $reason = number_unsendable_reason_for((string)$row['status'], $row['expires_on']);
                if ($reason !== null) $map[(string)$row['number']] = $reason;
            }
        } catch (PDOException $e) {
            // A read failure must not stop every send on the platform; the lines stay usable.
            Logger::error('numbers.unsendable_read_failed', ['exception' => $e]);
        }
        $system = system_default_originator();
        if ($system !== null) unset($map[$system]);
    }
    [$cache, $at, $day] = [$map, time(), $today];
    return $map;
}

function number_unsendable_reason(string $number): ?string {
    return number_unsendable_map()[$number] ?? null;
}

/** Drops the lines that cannot send from a list of numbers (strings) or picker rows (['number' => …]). */
function number_filter_sendable(array $items): array {
    $blocked = number_unsendable_map();
    if ($blocked === []) return $items;
    return array_values(array_filter($items, static function ($item) use ($blocked): bool {
        $number = is_array($item) ? (string)($item['number'] ?? '') : (string)$item;
        return !isset($blocked[$number]);
    }));
}

/**
 * Stores an uploaded commitment letter for line $numberId and returns its file name, or null when
 * no file was sent. Same rules as the KYC uploads: JPG/PNG/WEBP/PDF, at most 8 MB, outside the web
 * root, served only through /numbers.php?commitment=<id> to an admin.
 */
function number_commitment_store_upload(string $field, int $numberId): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new AppException('بارگذاری فایل با خطا مواجه شد.');
    if ($f['size'] > NUMBER_COMMITMENT_MAX_BYTES) throw new AppException('حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if (!isset(KYC_ALLOWED_MIME[$mime])) throw new AppException('فرمت تعهدنامه باید JPG، PNG، WEBP یا PDF باشد.');
    if (!is_dir(NUMBER_COMMITMENT_DIR)) mkdir(NUMBER_COMMITMENT_DIR, 0750, true);
    $name = 'n' . $numberId . '_' . bin2hex(random_bytes(8)) . '.' . KYC_ALLOWED_MIME[$mime];
    if (!move_uploaded_file($f['tmp_name'], NUMBER_COMMITMENT_DIR . '/' . $name)) {
        throw new AppException('ذخیره‌ی فایل ممکن نشد.');
    }
    return $name;
}

/** Absolute path of a stored commitment letter, or null when the name is not one we generated. */
function number_commitment_path(?string $name): ?string {
    if ($name === null || !preg_match('/^n\d+_[0-9a-f]{16}\.(jpg|png|webp|pdf)$/', $name)) return null;
    $path = NUMBER_COMMITMENT_DIR . '/' . $name;
    return is_file($path) ? $path : null;
}

/* ---------------------------------------------------------------------------------------------
 * Part 2 (db/migrations/2026_10_29_number_owners_and_quota.sql): status reason/date, renewal,
 * the user bandwidth ceiling, the opt-out bypass, owner info and bulk add.
 * ------------------------------------------------------------------------------------------- */

/** Statuses whose bandwidth does not count against the owner's ceiling (as in Vesal). */
const NUMBER_QUOTA_FREE_STATUSES = ['reserved', 'transferred', 'deleted'];

/** Most numbers one range or file may add at once. */
const NUMBER_BULK_MAX = 10000;

/** True once part 2 of the migration has run. */
function number_extras_ready(): bool {
    if (array_key_exists('__number_extras_ready', $GLOBALS)) return $GLOBALS['__number_extras_ready'];
    try {
        db()->query('SELECT status_reason, status_changed_at, bypass_optouts FROM ellsms_numbers LIMIT 0');
        db()->query('SELECT 1 FROM ellsms_user_line_quota LIMIT 0');
        db()->query('SELECT 1 FROM ellsms_number_owners LIMIT 0');
        return $GLOBALS['__number_extras_ready'] = true;
    } catch (PDOException) {
        return $GLOBALS['__number_extras_ready'] = false;
    }
}

/** A sender line typed by an admin (Persian digits allowed), or null when it is not 3–20 digits. */
function number_normalize_line(string $raw): ?string {
    $n = trim(from_persian_digits($raw));
    return preg_match('/^\d{3,20}$/', $n) ? $n : null;
}

/* ---------- Opt-out bypass («عبور از لیست سیاه داخلی») ---------- */

/** True when sends from $line skip the per-line opt-out list. Cached like number_unsendable_map(). */
function number_bypasses_optouts(string $line, bool $fresh = false): bool {
    static $set = null, $at = 0;
    if ($fresh || $set === null || time() - $at >= NUMBER_UNSENDABLE_TTL) {
        $set = [];
        if (number_extras_ready()) {
            try {
                foreach (db()->query('SELECT number FROM ellsms_numbers WHERE bypass_optouts = 1') as $row) {
                    $set[(string)$row['number']] = true;
                }
            } catch (PDOException $e) {
                Logger::error('numbers.optout_bypass_read_failed', ['exception' => $e]);
            }
        }
        $at = time();
    }
    return isset($set[$line]);
}

/* ---------- User bandwidth ceiling ---------- */

/** The user's bandwidth ceiling, or null for none. */
function number_user_quota(int $userId): ?int {
    if ($userId <= 0 || !number_extras_ready()) return null;
    $st = db()->prepare('SELECT bandwidth_max FROM ellsms_user_line_quota WHERE user_id = ?');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

/** Bandwidth of the user's counted lines, leaving out line $excludeId (the one being changed). */
function number_user_bandwidth_used(int $userId, int $excludeId = 0): int {
    if ($userId <= 0 || !number_details_ready()) return 0;
    $free = implode(',', array_map(static fn(string $s): string => db()->quote($s), NUMBER_QUOTA_FREE_STATUSES));
    $st = db()->prepare("SELECT COALESCE(SUM(bandwidth), 0) FROM ellsms_numbers WHERE assigned_user_id = ? AND id <> ? AND status NOT IN ({$free})");
    $st->execute([$userId, $excludeId]);
    return (int)$st->fetchColumn();
}

/**
 * Why giving user $userId a line of $bandwidth (in $status) would break their ceiling, or null when it
 * fits. $excludeId is the line itself when it is being changed rather than added.
 */
function number_quota_error(int $userId, int $bandwidth, string $status = 'active', int $excludeId = 0): ?string {
    if ($userId <= 0 || in_array($status, NUMBER_QUOTA_FREE_STATUSES, true)) return null;
    $quota = number_user_quota($userId);
    if ($quota === null) return null;
    $used = number_user_bandwidth_used($userId, $excludeId);
    if ($used + $bandwidth <= $quota) return null;
    return 'پهنای باند کافی نیست: سقف این کاربر ' . to_persian_digits((string)$quota) . ' است و '
        . to_persian_digits((string)$used) . ' از آن استفاده شده؛ این تغییر ' . to_persian_digits((string)$bandwidth) . ' لازم دارد.';
}

/** Sets (or with null removes) a user's ceiling. */
function number_set_user_quota(int $userId, ?int $max, int $actorId): void {
    if ($max === null) {
        db()->prepare('DELETE FROM ellsms_user_line_quota WHERE user_id = ?')->execute([$userId]);
    } else {
        db()->prepare('INSERT INTO ellsms_user_line_quota (user_id, bandwidth_max, updated_by) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE bandwidth_max = VALUES(bandwidth_max), updated_by = VALUES(updated_by)')
           ->execute([$userId, $max, $actorId]);
    }
    audit($actorId, 'number.user_quota', "user #{$userId} -> " . ($max ?? 'none'));
}

/* ---------- Status changes and renewal ---------- */

/**
 * Moves line $id to $status, recording why and when it left 'active' (status_reason /
 * status_changed_at). Refused when it would put the owner over their bandwidth ceiling.
 *
 * @return array{ok:bool, error?:string, number?:string}
 */
function number_set_status(int $id, string $status, string $reason, int $actorId): array {
    if (!isset(NUMBER_STATUSES[$status])) return ['ok' => false, 'error' => 'وضعیت انتخاب‌شده معتبر نیست.'];
    $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'شماره‌ی معتبری انتخاب نشده است.'];
    if ($row['status'] === $status) return ['ok' => true, 'number' => (string)$row['number']];
    if ($row['assigned_user_id'] !== null && in_array($row['status'], NUMBER_QUOTA_FREE_STATUSES, true)
        && ($err = number_quota_error((int)$row['assigned_user_id'], (int)$row['bandwidth'], $status, $id)) !== null) {
        return ['ok' => false, 'error' => $err];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (number_extras_ready()) {
        db()->prepare('UPDATE ellsms_numbers SET status = ?, status_reason = ?, status_changed_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$status, $status === 'active' ? '' : $reason, $id]);
    } else {
        db()->prepare('UPDATE ellsms_numbers SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
    audit($actorId, 'number.status', "{$row['number']} {$row['status']} -> {$status}" . ($reason !== '' ? " ({$reason})" : ''));
    number_unsendable_map(true);
    return ['ok' => true, 'number' => (string)$row['number']];
}

/** The same Jalali day one year after $ymd (Gregorian 'Y-m-d'); 30 Esfand falls back to the 29th. */
function number_add_jalali_year(string $ymd): string {
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    [$jy, $jm, $jd] = gregorian_to_jalali($y, $m, $d);
    [$gy, $gm, $gd] = jalali_to_gregorian($jy + 1, $jm, min($jd, jalali_month_length($jy + 1, $jm)));
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * «تمدید»: pushes the expiry one Jalali year past the later of today and the current expiry. A line
 * without an expiry has nothing to renew.
 *
 * @return array{ok:bool, error?:string, expires_on?:string, number?:string}
 */
function number_renew(int $id, int $actorId): array {
    $st = db()->prepare('SELECT number, expires_on FROM ellsms_numbers WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'شماره‌ی معتبری انتخاب نشده است.'];
    if ($row['expires_on'] === null) return ['ok' => false, 'error' => 'این شماره تاریخ انقضا ندارد که تمدید شود.'];
    $base = max((string)$row['expires_on'], number_today());
    $new = number_add_jalali_year($base);
    db()->prepare('UPDATE ellsms_numbers SET expires_on = ? WHERE id = ?')->execute([$new, $id]);
    audit($actorId, 'number.renew', "{$row['number']} {$row['expires_on']} -> {$new}");
    number_unsendable_map(true);
    return ['ok' => true, 'expires_on' => $new, 'number' => (string)$row['number']];
}

/* ---------- Owner info («اطلاعات مالک») ---------- */

const NUMBER_OWNER_TEXT_FIELDS = [
    'first_name' => 80, 'last_name' => 80, 'father_name' => 80, 'company_name' => 160, 'company_registration_no' => 30,
    'tel' => 20, 'email' => 120, 'province' => 60, 'city' => 60, 'address' => 500,
];

function number_owner_get(int $numberId): ?array {
    if (!number_extras_ready()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_number_owners WHERE number_id = ?');
    $st->execute([$numberId]);
    return $st->fetch() ?: null;
}

/**
 * Validates and stores the owner of line $numberId from form input. Dates come in as Gregorian
 * 'Y-m-d' (or null) — the caller reads them from the Jalali pickers.
 *
 * @return array{ok:bool, error?:string}
 */
function number_owner_save(int $numberId, array $in, ?string $birthDate, ?string $startDate, ?string $expireDate, int $actorId): array {
    $type = ($in['owner_type'] ?? 'real') === 'legal' ? 'legal' : 'real';
    $row = ['owner_type' => $type];
    foreach (NUMBER_OWNER_TEXT_FIELDS as $field => $max) {
        $row[$field] = mb_substr(trim((string)($in[$field] ?? '')), 0, $max);
    }
    $digits = static fn(string $k): string => preg_replace('/\D/', '', from_persian_digits((string)($in[$k] ?? '')));
    $row['national_id']         = $digits('national_id');
    $row['company_national_id'] = $digits('company_national_id');
    $row['postal_code']         = $digits('postal_code');
    $row['mobile']              = $digits('mobile');
    $row['gender']              = in_array($in['gender'] ?? '', ['male', 'female'], true) ? $in['gender'] : '';
    [$row['birth_date'], $row['start_date'], $row['expire_date']] = [$birthDate, $startDate, $expireDate];

    if ($type === 'real' && ($row['first_name'] === '' || $row['last_name'] === '')) return ['ok' => false, 'error' => 'نام و نام خانوادگی مالک لازم است.'];
    if ($type === 'legal' && $row['company_name'] === '') return ['ok' => false, 'error' => 'نام شرکت لازم است.'];
    if ($row['national_id'] !== '' && strlen($row['national_id']) !== 10) return ['ok' => false, 'error' => 'کد ملی باید دقیقاً ۱۰ رقم باشد.'];
    if ($row['company_national_id'] !== '' && strlen($row['company_national_id']) !== 11) return ['ok' => false, 'error' => 'شناسه‌ی ملی شرکت باید دقیقاً ۱۱ رقم باشد.'];
    if ($row['postal_code'] !== '' && strlen($row['postal_code']) !== 10) return ['ok' => false, 'error' => 'کد پستی باید دقیقاً ۱۰ رقم باشد.'];
    if ($row['mobile'] !== '' && !preg_match('/^(0|98)?9\d{9}$/', $row['mobile'])) return ['ok' => false, 'error' => 'شماره‌ی همراه مالک معتبر نیست.'];
    if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) return ['ok' => false, 'error' => 'ایمیل مالک معتبر نیست.'];
    if ($startDate !== null && $expireDate !== null && $expireDate < $startDate) return ['ok' => false, 'error' => 'تاریخ پایان مالکیت نباید پیش از تاریخ شروع باشد.'];

    $cols = array_keys($row);
    db()->prepare(
        'INSERT INTO ellsms_number_owners (number_id, ' . implode(', ', $cols) . ', updated_by) VALUES (?' . str_repeat(',?', count($cols)) . ',?)
         ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $cols)) . ', updated_by = VALUES(updated_by)'
    )->execute(array_merge([$numberId], array_values($row), [$actorId]));
    audit($actorId, 'number.owner_save', "#{$numberId} ({$type})");
    return ['ok' => true];
}

/* ---------- Bulk add (range / file) ---------- */

/**
 * Every number from $from to $to inclusive. Like Vesal, the two share a prefix and only the rest
 * counts up, so 20-digit lines work without overflowing an integer and leading zeros are kept.
 *
 * @return array{ok:bool, error?:string, numbers?:list<string>}
 */
function number_range(string $from, string $to): array {
    $from = number_normalize_line($from);
    $to = number_normalize_line($to);
    if ($from === null || $to === null) return ['ok' => false, 'error' => 'هر دو شماره‌ی ابتدا و انتهای بازه باید ۳ تا ۲۰ رقم باشند.'];
    if (strlen($from) !== strlen($to)) return ['ok' => false, 'error' => 'شماره‌ی ابتدا و انتهای بازه باید هم‌طول باشند.'];
    if (strcmp($from, $to) > 0) return ['ok' => false, 'error' => 'شماره‌ی ابتدای بازه نباید از انتهای آن بزرگ‌تر باشد.'];
    $p = 0;
    while ($p < strlen($from) && $from[$p] === $to[$p]) $p++;
    $prefix = substr($from, 0, $p);
    $width = strlen($from) - $p;
    if ($width === 0) return ['ok' => true, 'numbers' => [$from]];
    if ($width > 6) return ['ok' => false, 'error' => 'بازه بیش از حد بزرگ است؛ حداکثر ' . to_persian_digits(number_format(NUMBER_BULK_MAX)) . ' شماره در هر بار.'];
    [$a, $b] = [(int)substr($from, $p), (int)substr($to, $p)];
    if ($b - $a + 1 > NUMBER_BULK_MAX) return ['ok' => false, 'error' => 'بازه بیش از حد بزرگ است؛ حداکثر ' . to_persian_digits(number_format(NUMBER_BULK_MAX)) . ' شماره در هر بار.'];
    $out = [];
    for ($i = $a; $i <= $b; $i++) $out[] = $prefix . str_pad((string)$i, $width, '0', STR_PAD_LEFT);
    return ['ok' => true, 'numbers' => $out];
}

/** Result codes of number_bulk_add(), as shown in the report and its download. */
const NUMBER_BULK_RESULTS = [
    'added'     => 'افزوده شد',
    'exists'    => 'قبلاً ثبت شده',
    'invalid'   => 'نامعتبر',
    'duplicate' => 'تکراری در فهرست',
];

/**
 * Adds many lines at once with the same label / owner / status / expiry. Nothing is added when the
 * new lines would put the owner over their bandwidth ceiling (all or nothing, as in Vesal).
 *
 * @param list<string> $raw  the typed or uploaded values, in order
 * @param array{label?:string, user_id?:int, status?:string, expires_on?:?string} $opts
 * @return array{ok:bool, error?:string, rows?:list<array{0:string,1:string}>, counts?:array<string,int>}
 */
function number_bulk_add(array $raw, array $opts, int $actorId): array {
    if (count($raw) > NUMBER_BULK_MAX) return ['ok' => false, 'error' => 'حداکثر ' . to_persian_digits(number_format(NUMBER_BULK_MAX)) . ' شماره در هر بار.'];
    if ($raw === []) return ['ok' => false, 'error' => 'شماره‌ای برای افزودن پیدا نشد.'];
    $userId = (int)($opts['user_id'] ?? 0);
    $status = (string)($opts['status'] ?? 'active');
    if (!isset(NUMBER_STATUSES[$status])) $status = 'active';

    $rows = [];
    $candidates = [];
    foreach ($raw as $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $n = number_normalize_line($value);
        if ($n === null) { $rows[] = [$value, 'invalid']; continue; }
        if (isset($candidates[$n])) { $rows[] = [$n, 'duplicate']; continue; }
        $candidates[$n] = count($rows);
        $rows[] = [$n, 'added'];
    }
    if ($candidates) {
        foreach (array_chunk(array_map('strval', array_keys($candidates)), 1000) as $chunk) {
            $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE number IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $existing) {
                $rows[$candidates[(string)$existing]][1] = 'exists';
                unset($candidates[(string)$existing]);
            }
        }
    }
    if ($userId > 0 && $candidates && ($err = number_quota_error($userId, count($candidates), $status)) !== null) {
        return ['ok' => false, 'error' => $err];
    }
    if ($candidates) {
        $detailsReady = number_details_ready();
        db_transaction(static function (PDO $db) use ($candidates, $opts, $userId, $status, $detailsReady): void {
            $ins = $detailsReady
                ? $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id, status, expires_on) VALUES (?,?,?,?,?)')
                : $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)');
            foreach (array_keys($candidates) as $n) {
                $args = [(string)$n, (string)($opts['label'] ?? ''), $userId ?: null];
                if ($detailsReady) array_push($args, $status, $opts['expires_on'] ?? null);
                $ins->execute($args);
            }
        });
    }
    $counts = array_fill_keys(array_keys(NUMBER_BULK_RESULTS), 0);
    foreach ($rows as [, $code]) $counts[$code]++;
    audit($actorId, 'number.bulk_add', "added={$counts['added']} exists={$counts['exists']} invalid={$counts['invalid']} duplicate={$counts['duplicate']}" . ($userId ? " user=#{$userId}" : ''));
    number_unsendable_map(true);
    return ['ok' => true, 'rows' => $rows, 'counts' => $counts];
}
