<?php
/**
 * «افزودن لغو۱۱ به انتهای پیام» — the opt-out footer option on every panel send form.
 *
 * Every panel page that composes a message (new-send, the old send page, P2P, smart, postal-code,
 * regional and LBA) shows one checkbox, ON by default. While it is on, the text "\nلغو۱۱" is appended
 * to each message right before it is priced or sent — unless the message already carries an opt-out
 * marker anywhere («لغو11»، «لغو ۱۱»، «لغو: ۱۱»… Persian, Arabic or Latin digits), so it is never doubled.
 *
 * The web service (/api/v1) is deliberately untouched: integrations send exactly what they post.
 * The large-file path (p2p/smart files over the sync limit) stores the choice on
 * ellsms_import_jobs.add_optout_suffix (db/migrations/2026_11_23_import_job_optout_suffix.sql) and the
 * import worker applies it per row. See docs/optout-suffix.md.
 */

declare(strict_types=1);

const OPTOUT_SUFFIX_TEXT = 'لغو۱۱';
const OPTOUT_SUFFIX_FIELD = 'add_optout';

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

/**
 * Whether the submitted form asked for the suffix. The form posts a hidden "0" before the checkbox's
 * "1", so an unticked box is an explicit "0"; a request that has no field at all (an older cached
 * form, a script) gets the default — ON.
 */
function optout_suffix_requested(?array $input = null): bool {
    $input ??= $_POST;
    if (!array_key_exists(OPTOUT_SUFFIX_FIELD, $input)) {
        return true;
    }
    return (string)$input[OPTOUT_SUFFIX_FIELD] === '1';
}

/**
 * The checkbox (with its hidden "0" companion). $checked defaults to the posted value, or ON when
 * the form has not been submitted yet.
 */
function optout_suffix_checkbox_html(?bool $checked = null, string $extraClass = ''): string {
    $checked ??= optout_suffix_requested();
    $name = OPTOUT_SUFFIX_FIELD;
    return '<label class="check composer-check optout-suffix-check' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '">'
        . '<input type="hidden" name="' . $name . '" value="0">'
        . '<input type="checkbox" name="' . $name . '" value="1" data-optout-suffix="' . e(OPTOUT_SUFFIX_TEXT) . '"' . ($checked ? ' checked' : '') . '> '
        . 'افزودن «' . e(OPTOUT_SUFFIX_TEXT) . '» به انتهای پیام'
        . '<span class="hint" style="display:block;margin:2px 0 0">اگر «لغو۱۱» از قبل در متن باشد دوباره اضافه نمی‌شود؛ این متن در شمارش کاراکتر و هزینه حساب می‌شود.</span>'
        . '</label>';
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
