<?php

namespace Tests\Unit;

use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use App\Support\Geo\Polyline;
use PHPUnit\Framework\TestCase;

/**
 * Geometry underpins ETA, next-stop and alighting detection, so it is tested
 * against known distances rather than against itself.
 */
class GeoTest extends TestCase
{
    public function test_haversine_matches_a_known_distance(): void
    {
        // Bandar Abbas city centre to the airport: ~11.5 km great-circle.
        $centre = new Coordinate(27.1832, 56.2666);
        $airport = new Coordinate(27.2183, 56.3778);

        $distance = Distance::between($centre, $airport);

        $this->assertEqualsWithDelta(11_600, $distance, 400);
    }

    public function test_distance_between_a_point_and_itself_is_zero(): void
    {
        $point = new Coordinate(27.1832, 56.2666);

        $this->assertSame(0.0, round(Distance::between($point, $point), 6));
    }

    public function test_one_degree_of_latitude_is_about_111_kilometres(): void
    {
        $distance = Distance::between(new Coordinate(27.0, 56.0), new Coordinate(28.0, 56.0));

        $this->assertEqualsWithDelta(110_900, $distance, 800);
    }

    public function test_bearing_points_north_and_east_correctly(): void
    {
        $origin = new Coordinate(27.0, 56.0);

        $this->assertEqualsWithDelta(0, Distance::bearing($origin, new Coordinate(27.1, 56.0)), 1);
        $this->assertEqualsWithDelta(90, Distance::bearing($origin, new Coordinate(27.0, 56.1)), 1);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->expectException(DomainException::class);

        new Coordinate(91.0, 0.0);
    }

    public function test_a_point_projects_onto_the_middle_of_a_segment(): void
    {
        $result = Distance::toSegment(
            new Coordinate(27.005, 56.0),   // offset north of the segment
            new Coordinate(27.0, 55.99),
            new Coordinate(27.0, 56.01),
        );

        $this->assertEqualsWithDelta(0.5, $result['t'], 0.02);
        $this->assertEqualsWithDelta(555, $result['distance'], 40);
    }

    public function test_projection_clamps_to_the_segment_ends(): void
    {
        // A point well before the start must clamp to t=0, not extrapolate.
        $result = Distance::toSegment(
            new Coordinate(27.0, 55.90),
            new Coordinate(27.0, 56.00),
            new Coordinate(27.0, 56.01),
        );

        $this->assertSame(0.0, $result['t']);
    }

    public function test_polyline_length_is_the_sum_of_its_segments(): void
    {
        $line = Polyline::fromArray([
            ['lat' => 27.00, 'lng' => 56.00],
            ['lat' => 27.01, 'lng' => 56.00],
            ['lat' => 27.02, 'lng' => 56.00],
        ]);

        // Two hops of 0.01 degrees latitude, ~1.11 km each.
        $this->assertEqualsWithDelta(2_220, $line->length(), 40);
    }

    public function test_snapping_reports_travelled_distance_along_the_line(): void
    {
        $line = Polyline::fromArray([
            ['lat' => 27.00, 'lng' => 56.00],
            ['lat' => 27.02, 'lng' => 56.00],
        ]);

        // Three quarters of the way along, slightly off to one side.
        $snap = $line->snap(new Coordinate(27.015, 56.0002));

        $this->assertEqualsWithDelta($line->length() * 0.75, $snap['offset'], 60);
        $this->assertLessThan(40, $snap['distance']);
    }

    public function test_an_empty_polyline_snaps_without_error(): void
    {
        $line = Polyline::fromArray([['lat' => 27.0, 'lng' => 56.0]]);

        $this->assertTrue($line->isEmpty());
        $this->assertSame(INF, $line->snap(new Coordinate(27.0, 56.0))['distance']);
    }

    public function test_bounding_box_contains_the_requested_radius(): void
    {
        $centre = new Coordinate(27.1832, 56.2666);
        $box = Distance::boundingBox($centre, 1000);

        $northEdge = new Coordinate($box['max_lat'], $centre->lng);

        $this->assertGreaterThanOrEqual(999, Distance::between($centre, $northEdge));
    }
}
