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
