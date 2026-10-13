<?php
/**
 * Numbered pagination shared by every list page: «→ قبلی ۱ … ۴ ۵ [۶] ۷ ۸ … ۱۲۰ بعدی ←», the
 * "page X of Y" line and a "go to page" box. A page counts its rows once, then reads one page with
 * LIMIT/OFFSET — pager_page() / pager_offset() / pager_links() are all it needs.
 */

declare(strict_types=1);

/** The requested page number from the query string (1 when absent or invalid). */
function pager_page(string $param = 'page'): int {
    return max(1, (int)from_persian_digits((string)($_GET[$param] ?? '1')));
}

/** Number of pages for $total rows at $per per page (at least 1, so an empty list is "page 1 of 1"). */
function pager_pages(int $total, int $per): int {
    return max(1, (int)ceil($total / max(1, $per)));
}

/**
 * [page, offset] for a list of $total rows: the requested page, clamped to the last page so a stale
 * link (rows deleted since) still lands on real rows.
 *
 * @return array{0:int, 1:int}
 */
function pager_offset(int $total, int $per, string $param = 'page'): array {
    $page = min(pager_page($param), pager_pages($total, $per));
    return [$page, ($page - 1) * $per];
}

/**
 * The page numbers to show: the first and last page, the current one and $around on each side;
 * null marks a gap («…»).
 *
 * @return list<?int>
 */
function pager_window(int $page, int $pages, int $around = 2): array {
    $shown = array_unique(array_merge([1, $pages], range(max(1, $page - $around), min($pages, $page + $around))));
    sort($shown);
    $out = [];
    $last = 0;
    foreach ($shown as $p) {
        if ($last && $p === $last + 2) {
            $out[] = $last + 1; // a gap of one page shows that page, not «…»
        } elseif ($last && $p > $last + 2) {
            $out[] = null;
        }
        $out[] = $p;
        $last = $p;
    }
    return $out;
}

/**
 * The pagination bar. $query is the current page's other query parameters (filters, tab…), kept on
 * every link; $param names this list's page parameter (a page with two lists uses two). Prints
 * nothing when everything fits on one page.
 */
function pager_links(int $page, int $pages, array $query = [], string $param = 'page', ?int $total = null, string $fragment = ''): string {
    if ($pages <= 1) {
        return '';
    }
    unset($query[$param]);
    $href = static fn(int $p): string => '?' . http_build_query($query + [$param => $p]) . ($fragment !== '' ? '#' . $fragment : '');
    $fa = static fn(int $n): string => to_persian_digits(number_format($n));

    $h = '<nav class="pagination pager" aria-label="صفحه‌ها">';
    $h .= $page > 1
        ? '<a class="btn btn-sm" href="' . e($href($page - 1)) . '" rel="prev">→ قبلی</a>'
        : '<span class="btn btn-sm btn-ghost is-disabled">→ قبلی</span>';
    foreach (pager_window($page, $pages) as $p) {
        if ($p === null) {
            $h .= '<span class="pager-gap">…</span>';
        } elseif ($p === $page) {
            $h .= '<span class="btn btn-sm btn-primary" aria-current="page">' . $fa($p) . '</span>';
        } else {
            $h .= '<a class="btn btn-sm" href="' . e($href($p)) . '">' . $fa($p) . '</a>';
        }
    }
    $h .= $page < $pages
        ? '<a class="btn btn-sm" href="' . e($href($page + 1)) . '" rel="next">بعدی ←</a>'
        : '<span class="btn btn-sm btn-ghost is-disabled">بعدی ←</span>';

    $h .= '<span class="pager-info">صفحه ' . $fa($page) . ' از ' . $fa($pages)
        . ($total !== null ? ' · ' . $fa($total) . ' مورد' : '') . '</span>';

    if ($pages > 7) {
        // A GET form carrying the other parameters as hidden fields, so jumping keeps the filters.
        $h .= '<form class="pager-jump" method="get"' . ($fragment !== '' ? ' action="#' . e($fragment) . '"' : '') . '>';
        foreach (explode('&', http_build_query($query)) as $pair) {
            if ($pair === '') continue;
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $h .= '<input type="hidden" name="' . e(urldecode($k)) . '" value="' . e(urldecode($v)) . '">';
        }
        $h .= '<label>برو به صفحه <input type="number" name="' . e($param) . '" min="1" max="' . $pages . '" value="' . $page . '" class="ltr"></label>'
            . '<button class="btn btn-sm" type="submit">برو</button></form>';
    }
    return $h . '</nav>';
}
