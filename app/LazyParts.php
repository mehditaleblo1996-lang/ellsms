<?php
/**
 * Lazily loaded page parts: a slow report page answers with its shell (header, filters, empty
 * placeholders) at once, and each placeholder then fetches its own section from the same URL with
 * ?_part=<name> (public/assets/js/lazy-parts.js). The parts load in parallel, a click on a slow page
 * never sits on a blank screen, and one part that fails or times out shows a retry button instead
 * of taking the whole page down with it.
 *
 * A page serves a part like this, after its own login/permission checks and before the header:
 *
 *     if (lazy_part_begin('summary')) { ...queries...; ?>fragment HTML<?php exit; }
 *
 * and the shell prints lazy_part_placeholder('summary') where that fragment goes. A fragment can fill
 * a tab's count in the shell with lazy_part_tab_count(), and a placeholder can ask to reload the page
 * every N seconds once its fragment says so (lazy_part_refresh_marker()). A live section instead
 * re-fetches only itself in place (lazy_part_live_placeholder()), for a monitoring view.
 */

declare(strict_types=1);

const LAZY_PART_PARAM = '_part';

/**
 * True when this request asks for part $name: the session is released (parts of one page load in
 * parallel, and PHP's session lock would otherwise run them one after another), the response is
 * marked as that part (so the browser can tell it from a login page it was redirected to), and
 * `_part` is dropped from $_GET so pager and filter links built from it point at the page itself.
 */
function lazy_part_begin(string $name): bool {
    if ((string)($_GET[LAZY_PART_PARAM] ?? '') !== $name) {
        return false;
    }
    unset($_GET[LAZY_PART_PARAM]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Lazy-Part: ' . $name);
    }
    return true;
}

/** True when this request asks for any part — a page uses it to skip shell-only work. */
function lazy_part_requested(): bool {
    return (string)($_GET[LAZY_PART_PARAM] ?? '') !== '';
}

/** The empty box the shell prints where part $name goes; $minHeight keeps the layout from jumping. */
function lazy_part_placeholder(string $name, int $minHeight = 120, string $label = 'در حال بارگذاری…'): string {
    return '<div class="lazy-part" data-lazy-part="' . e($name) . '" aria-busy="true" style="min-height:' . $minHeight . 'px">'
        . '<div class="lazy-part-status"><span class="lazy-spinner" aria-hidden="true"></span>' . e($label) . '</div>'
        . '</div>';
}

/**
 * Like lazy_part_placeholder(), but the box stays and re-fetches its part every $seconds while the
 * page is visible: the next request starts only after the previous one answered, a failed refresh
 * keeps the last content (marked stale) and tries again on the next tick, and nothing is fetched
 * while the tab is hidden. The page can ask for an immediate refresh by dispatching
 * `lazy-part:refresh` on the box; every new content fires `lazy-part:loaded` on it.
 */
function lazy_part_live_placeholder(string $name, int $seconds, int $minHeight = 120, string $label = 'در حال بارگذاری…'): string {
    return '<div class="lazy-part" data-lazy-part="' . e($name) . '" data-lazy-poll="' . max(1, $seconds) . '" aria-busy="true" aria-live="off" style="min-height:' . $minHeight . 'px">'
        . '<div class="lazy-part-status"><span class="lazy-spinner" aria-hidden="true"></span>' . e($label) . '</div>'
        . '</div>';
}

/** Inside a fragment: the count shown on the shell's tab $tab (`.tabs-nav [data-tab=…] .tab-count`). */
function lazy_part_tab_count(string $tab, string $text): string {
    return '<span hidden data-lazy-tab-count="' . e($tab) . '">' . e($text) . '</span>';
}

/** Inside a fragment: reload the whole page after $seconds while it is visible (e.g. a bulk job is sending). */
function lazy_part_refresh_marker(int $seconds): string {
    return '<span hidden data-lazy-refresh="' . max(10, $seconds) . '"></span>';
}
