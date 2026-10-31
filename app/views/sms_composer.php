<?php
/**
 * Markup of the send composer shared by public/send.php and public/new-send.php. Every element is
 * filled in by public/assets/js/sms-composer.js; without JavaScript the page is the plain form it
 * always was, and «محاسبه‌ی هزینه» still prices on the server.
 */
require_once __DIR__ . '/panel-icons.php';
require_once __DIR__ . '/../LiveCostEstimate.php';

/** Under the message textarea: segment fill, character count and room left before the next part. */
function composer_meter_html(): string {
    return '<div class="composer-meter" id="composerMeter">'
        . '<div class="meter-track" aria-hidden="true"><span class="meter-fill"></span></div>'
        . '<div class="meter-row"><span class="meter-count">پیام هنوز خالی است</span><span class="meter-next"></span></div>'
        . '<span class="meter-encoding"></span>'
        . '<p class="meter-note" hidden></p>'
        . '</div>';
}

/** Under the recipients textarea: valid / invalid / duplicate chips and the list clean-up buttons. */
function composer_dest_stats_html(): string {
    return '<div class="composer-dest-stats" id="composerDestStats">'
        . '<div class="composer-chips"></div>'
        . '<div class="composer-dest-actions">'
        . '<button type="button" class="btn btn-sm btn-ghost" id="composerClean" hidden>حذف نامعتبرها و تکراری‌ها</button>'
        . '<button type="button" class="btn btn-sm btn-ghost" id="composerUndo" hidden>بازگرداندن فهرست</button>'
        . '</div></div>'
        . '<details class="composer-invalid" id="composerInvalid" hidden><summary></summary><ul></ul></details>';
}

/** The sticky side column: phone preview, live summary and $actionsHtml (the page's submit buttons). */
function composer_side_html(string $actionsHtml): string {
    return '<aside class="composer-side" aria-label="پیش‌نمایش و هزینه">'
        . '<div class="card composer-phone-wrap">'
        . '<div class="composer-phone" role="group" aria-label="پیش‌نمایش پیام روی گوشی گیرنده">'
        . '<div class="phone-island"></div>'
        . '<div class="phone-head"><span class="phone-avatar">' . panel_icon('message') . '</span>'
        . '<span class="phone-title"><span class="phone-sender" id="composerPhoneSender">خط ارسال</span><span class="phone-kind">پیامک</span></span></div>'
        . '<div class="phone-body"><span class="phone-day">امروز</span>'
        . '<div class="phone-bubble is-empty" id="composerPhoneBubble" dir="auto">متن پیام اینجا، همان‌طور که گیرنده می‌بیند، نمایش داده می‌شود.</div>'
        . '<span class="phone-meta" id="composerPhoneMeta">اکنون</span></div>'
        . '<div class="phone-bar" aria-hidden="true">پیامک</div>'
        . '</div>'
        . '<p class="hint">نمایش روی گوشی‌ها کمی متفاوت است؛ متن و تعداد بخش‌ها همین است.</p>'
        . '</div>'
        . '<div class="card composer-summary">'
        . '<h2>خلاصه‌ی ارسال <span class="cost-live">محاسبه‌ی زنده</span></h2>'
        . '<div class="composer-cost" id="composerCost" data-state="idle" aria-busy="false">'
        . '<div class="cost-body"><p class="cost-message">برای دیدن هزینه، متن پیام و دست‌کم یک گیرنده وارد کنید.</p></div>'
        . '</div>'
        . '<p class="sr-only" id="composerAnnounce" role="status" aria-live="polite"></p>'
        . '<div class="composer-actions">' . $actionsHtml . '</div>'
        . '</div>'
        . '</aside>';
}

/**
 * The <option>s of a send page's sender picker, from user_sendable_numbers(). When the user has
 * shared lines (docs/shared-numbers.md) they are listed in their own group, labelled send-only, so a
 * customer can tell a line lent to them from their own. $serviceLines (number => true) adds the
 * «خدماتی» mark and data-service attribute new-send.php's blacklist toggle reads.
 */
function originator_options_html(array $numbers, string $selected, array $serviceLines = []): string {
    $option = static function (array $n) use ($selected, $serviceLines): string {
        $number = (string)$n['number'];
        $isService = isset($serviceLines[$number]);
        return '<option value="' . e($number) . '" data-service="' . ($isService ? '1' : '0') . '"'
            . ($number === $selected ? ' selected' : '') . '>'
            . e($number) . ($isService ? ' — خدماتی' : '') . (!empty($n['shared']) ? ' — اشتراکی' : '')
            . '</option>';
    };
    $own = array_filter($numbers, static fn(array $n): bool => empty($n['shared']));
    $shared = array_filter($numbers, static fn(array $n): bool => !empty($n['shared']));
    if ($shared === []) {
        return implode('', array_map($option, $own));
    }
    $html = $own ? '<optgroup label="خطوط من">' . implode('', array_map($option, $own)) . '</optgroup>' : '';
    return $html . '<optgroup label="خطوط اشتراکی (فقط ارسال)">' . implode('', array_map($option, $shared)) . '</optgroup>';
}

/** One line under the picker when shared lines are on it: what «اشتراکی» means for the user. */
function originator_shared_hint_html(array $numbers): string {
    foreach ($numbers as $n) {
        if (!empty($n['shared'])) {
            return '<div class="hint">خطوط «اشتراکی» را مدیر سامانه با شما به اشتراک گذاشته است: می‌توانید از آن‌ها ارسال کنید، ولی پیام‌های دریافتی‌شان به صاحب خط می‌رسد.</div>';
        }
    }
    return '';
}
