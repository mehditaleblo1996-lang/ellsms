<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The numbered pagination every list page shares (app/Pagination.php): which page numbers show,
 * how a requested page is clamped, and that every link keeps the page's filters.
 */
final class PaginationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['page'], $_GET['gw_page']);
    }

    public function testTheWindowShowsBothEndsAndTheNeighboursOfTheCurrentPage(): void
    {
        $this->assertSame([1], pager_window(1, 1));
        $this->assertSame([1, 2, 3, 4, 5], pager_window(1, 5));
        $this->assertSame([1, 2, 3, null, 120], pager_window(1, 120));
        $this->assertSame([1, null, 4, 5, 6, 7, 8, null, 120], pager_window(6, 120));
        $this->assertSame([1, null, 118, 119, 120], pager_window(120, 120));
    }

    public function testAGapOfOnePageShowsThatPageInsteadOfAnEllipsis(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], pager_window(4, 7));
        $this->assertSame([1, 2, 3, 4, 5, null, 9], pager_window(3, 9));
    }

    public function testTheRequestedPageIsClampedToTheLastOne(): void
    {
        $_GET['page'] = '۹۹';
        $this->assertSame([3, 200], pager_offset(250, 100));
        $_GET['page'] = '-4';
        $this->assertSame([1, 0], pager_offset(250, 100));
        $_GET['gw_page'] = '2';
        $this->assertSame([2, 50], pager_offset(250, 50, 'gw_page'));
        $this->assertSame([1, 0], pager_offset(0, 50, 'gw_page'), 'an empty list is page 1 of 1');
    }

    public function testASinglePagePrintsNothing(): void
    {
        $this->assertSame('', pager_links(1, 1));
    }

    public function testLinksKeepTheFiltersAndMarkTheCurrentPage(): void
    {
        $html = pager_links(6, 120, ['group' => 'VIP مشتریان', 'page' => 3], 'page', 11950);

        $this->assertStringContainsString('href="?group=VIP+%D9%85%D8%B4%D8%AA%D8%B1%DB%8C%D8%A7%D9%86&amp;page=5" rel="prev"', $html);
        $this->assertStringContainsString('href="?group=VIP+%D9%85%D8%B4%D8%AA%D8%B1%DB%8C%D8%A7%D9%86&amp;page=120"', $html);
        $this->assertStringContainsString('aria-current="page">۶<', $html);
        $this->assertStringContainsString('صفحه ۶ از ۱۲۰ · ۱۱,۹۵۰ مورد', $html);
        // The jump box re-sends the filters as hidden fields, but not the stale page number.
        $this->assertStringContainsString('<input type="hidden" name="group" value="VIP مشتریان">', $html);
        $this->assertStringNotContainsString('name="page" value="3"', $html);
        $this->assertStringContainsString('name="page" min="1" max="120" value="6"', $html);
    }

    public function testTheEndsHaveNoDeadLinksAndAFragmentIsKept(): void
    {
        $first = pager_links(1, 3, [], 'gw_page', null, 'gateway');
        $this->assertStringContainsString('<span class="btn btn-sm btn-ghost is-disabled">→ قبلی</span>', $first);
        $this->assertStringContainsString('href="?gw_page=2#gateway" rel="next"', $first);
        $this->assertStringNotContainsString('pager-jump', $first, 'a short list needs no jump box');

        $last = pager_links(3, 3);
        $this->assertStringContainsString('<span class="btn btn-sm btn-ghost is-disabled">بعدی ←</span>', $last);
    }
}
