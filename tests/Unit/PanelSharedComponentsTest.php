<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/LazyParts.php';
require_once dirname(__DIR__, 2) . '/app/views/panel-icons.php';

/** Loading skeletons of lazily loaded parts and the shared empty state. */
final class PanelSharedComponentsTest extends TestCase
{
    public function testPlaceholderPrintsASkeletonOfItsShapeWithAReadableLabel(): void
    {
        $html = lazy_part_placeholder('recent', 260, shape: 'table');
        self::assertStringContainsString('class="lazy-part lazy-part-table"', $html);
        self::assertStringContainsString('data-lazy-part="recent"', $html);
        self::assertStringContainsString('min-height:260px', $html);
        self::assertStringContainsString('<span class="sr-only lazy-part-label">در حال بارگذاری…</span>', $html);
        self::assertStringContainsString('<div class="skel-wrap" aria-hidden="true">', $html);
        self::assertSame(6, substr_count($html, 'skel-row'), 'a header row and five rows');
    }

    public function testUnknownShapeFallsBackToABlock(): void
    {
        self::assertStringContainsString('lazy-part-block', lazy_part_placeholder('x', shape: '"><script>'));
        self::assertSame(4, substr_count(lazy_part_skeleton('stats'), 'skel-card'));
        self::assertSame(7, substr_count(lazy_part_skeleton('chart'), 'style="height:'));
    }

    public function testLivePlaceholderKeepsItsPollingAndGetsASkeleton(): void
    {
        $html = lazy_part_live_placeholder('live', 3, 420, shape: 'table');
        self::assertStringContainsString('data-lazy-poll="3"', $html);
        self::assertStringContainsString('skel-row', $html);
    }

    public function testEmptyStateEscapesAndOffersTheNextStep(): void
    {
        $html = panel_empty_state('هنوز <مخاطبی> نیست', 'یکی اضافه کنید', ['/contacts.php#add', 'افزودن'], 'users');
        self::assertStringContainsString('هنوز &lt;مخاطبی&gt; نیست', $html);
        self::assertStringContainsString('<a class="btn btn-sm btn-primary" href="/contacts.php#add">افزودن</a>', $html);
        self::assertStringContainsString('class="empty-state-icon"><svg', $html);
        self::assertStringNotContainsString('<a ', panel_empty_state('خالی'), 'no action, no button');
        self::assertStringNotContainsString('empty-state-text', panel_empty_state('خالی'));
    }
}
