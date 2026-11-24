<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * lba_parse_polygons() (app/Lba.php): the provider fails with a bare statusCode 25 on a clockwise ring
 * or one whose edges cross, so rings are sent counter-clockwise and crossings are refused here.
 */
final class LbaPolygonTest extends TestCase
{
    private const CLOCKWISE = '[[[51.25,35.78],[51.50,35.77],[51.45,35.65],[51.24,35.66]]]';
    private const COUNTER_CLOCKWISE = '[[[51.25,35.78],[51.24,35.66],[51.45,35.65],[51.50,35.77]]]';

    public function testAClockwiseRingIsReversedAndStaysClosed(): void
    {
        $polys = \lba_parse_polygons(self::CLOCKWISE);
        $this->assertSame([[51.25, 35.78], [51.24, 35.66], [51.45, 35.65], [51.5, 35.77], [51.25, 35.78]], $polys[0]);
        $this->assertGreaterThan(0, \lba_ring_signed_area($polys[0]));
    }

    public function testACounterClockwiseRingIsKeptAsDrawn(): void
    {
        $this->assertSame(\lba_parse_polygons(self::CLOCKWISE), \lba_parse_polygons(self::COUNTER_CLOCKWISE));
    }

    public function testCrossingEdgesAreRefused(): void
    {
        $bowTie = '[[[51.25,35.78],[51.45,35.65],[51.50,35.77],[51.24,35.66]]]';
        $this->assertIsString(\lba_parse_polygons($bowTie));
        $touching = '[[[51.0,35.0],[51.2,35.0],[51.2,35.2],[51.1,35.0],[51.0,35.2]]]';
        $this->assertIsString(\lba_parse_polygons($touching), 'a corner on another edge');
    }

    public function testAPointClickedTwiceIsDropped(): void
    {
        $polys = \lba_parse_polygons('[[[51.3753,35.7448],[51.3760,35.7440],[51.3760,35.7440],[51.3745,35.7432]]]');
        $this->assertIsArray($polys);
        $this->assertCount(4, $polys[0]);
    }

    public function testConcaveShapesAreAccepted(): void
    {
        $notch = '[[[51.0,35.0],[51.4,35.0],[51.4,35.4],[51.2,35.2],[51.0,35.4]]]';
        $this->assertIsArray(\lba_parse_polygons($notch));
    }
}
