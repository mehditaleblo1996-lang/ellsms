<?php
/**
 * «افزودن لغو۱۱ به انتهای پیام» — the opt-out footer option on every panel send form.
 *
 * A platform admin turns it on/off per user (ellsms_meta.optout_suffix, default ON); customers have no
 * checkbox. While it is on, every panel page that composes a message (new-send, the old send page,
 * P2P, smart, postal-code, regional and LBA) appends "\nلغو۱۱" to each message right before it is
 * priced or sent — unless the message already carries an opt-out
 * marker anywhere («لغو11»، «لغو ۱۱»، «لغو: ۱۱»… Persian, Arabic or Latin digits), so it is never doubled.
 *
 * The web service (/api/v1) is deliberately untouched: integrations send exactly what they post.
 * Schema: db/migrations/2026_11_24_user_optout_suffix.sql (per user).
 * The large-file path (p2p/smart files over the sync limit) stores the choice on
 * ellsms_import_jobs.add_optout_suffix (db/migrations/2026_11_23_import_job_optout_suffix.sql) and the
 * import worker applies it per row. See docs/optout-suffix.md.
 */

declare(strict_types=1);

const OPTOUT_SUFFIX_TEXT = 'لغو۱۱';

/** True when $content already contains an opt-out marker («لغو» followed by 11 in any digit set). */
function optout_suffix_present(string $content): bool {
    return preg_match('/لغو\s*[:：\-]?\s*(?:11|۱۱|١١)(?![0-9۰-۹٠-٩])/u', $content) === 1;
}

/** $content with "\nلغو۱۱" appended, unless it is empty or already carries the marker. */
function optout_suffix_apply(string $content): string {
    $trimmed = rtrim($content);
    if ($trimmed === '' || optout_suffix_present($trimmed)) {
        return $content;
    }
    return $trimmed . "\n" . OPTOUT_SUFFIX_TEXT;
}

/** $content with the suffix applied only when $enabled. */
function optout_suffix_maybe(string $content, bool $enabled): string {
    return $enabled ? optout_suffix_apply($content) : $content;
}

/** Whether ellsms_meta has optout_suffix yet (db/migrations/2026_11_24_user_optout_suffix.sql). */
function optout_suffix_user_column_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            db()->query('SELECT optout_suffix FROM ellsms_meta LIMIT 0');
            $has = true;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Whether messages this user sends from the panel get the suffix. A platform admin decides it per
 * user (Users → user, «افزودن لغو۱۱ به انتهای پیام»); the customer has no control over it. Default ON
 * — also for every user before the migration is applied.
 */
function optout_suffix_for_user(int $userId): bool {
    static $cache = [];
    if ($userId <= 0 || !optout_suffix_user_column_available()) {
        return true;
    }
    if (!array_key_exists($userId, $cache)) {
        $st = db()->prepare('SELECT optout_suffix FROM ellsms_meta WHERE user_id = ?');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        $cache[$userId] = $v === false ? true : (int)$v === 1;
    }
    return $cache[$userId];
}

/** Admin: turn the suffix on/off for one user. Returns true when something changed (audited). */
function optout_suffix_set_for_user(int $userId, bool $on, int $byUserId): bool {
    if ($userId <= 0 || !optout_suffix_user_column_available()) {
        return false;
    }
    $st = db()->prepare('UPDATE ellsms_meta SET optout_suffix = ? WHERE user_id = ? AND optout_suffix <> ?');
    $st->execute([$on ? 1 : 0, $userId, $on ? 1 : 0]);
    if ($st->rowCount() > 0) {
        audit($byUserId, $on ? 'user.optout_suffix_on' : 'user.optout_suffix_off', 'user_id=' . $userId);
        return true;
    }
    return false;
}

/**
 * Read-only note under the message box when the suffix is on for this user (no checkbox — the admin
 * decides). Its data-optout-suffix attribute is what the composer / LBA counters look for.
 */
function optout_suffix_notice_html(bool $on): string {
    if (!$on) {
        return '';
    }
    return '<p class="hint optout-suffix-note" data-optout-suffix="' . e(OPTOUT_SUFFIX_TEXT) . '">'
        . '«' . e(OPTOUT_SUFFIX_TEXT) . '» در یک خط جدید به انتهای پیام اضافه می‌شود (اگر از قبل در متن نباشد) و در شمارش کاراکتر و هزینه حساب می‌شود.'
        . '</p>';
}

/** Cached: does ellsms_import_jobs have the add_optout_suffix column yet (migration applied)? */
function import_jobs_have_optout_column(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $st = db()->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'add_optout_suffix'"
            );
            $has = (int)$st->fetchColumn() > 0;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}
