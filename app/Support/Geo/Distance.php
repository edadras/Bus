<?php

namespace App\Support\Geo;

/**
 * Geodesic helpers. The platform works at city scale (tens of kilometres), so
 * the haversine formula on a spherical earth is accurate to well under a metre
 * for our purposes and is far cheaper than Vincenty.
 */
final class Distance
{
    public const EARTH_RADIUS_METERS = 6_371_008.8;

    public static function between(Coordinate $a, Coordinate $b): float
    {
        $latA = deg2rad($a->lat);
        $latB = deg2rad($b->lat);
        $dLat = $latB - $latA;
        $dLng = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2 + cos($latA) * cos($latB) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }

    /** Initial bearing from $a to $b in degrees clockwise from true north. */
    public static function bearing(Coordinate $a, Coordinate $b): float
    {
        $latA = deg2rad($a->lat);
        $latB = deg2rad($b->lat);
        $dLng = deg2rad($b->lng - $a->lng);

        $y = sin($dLng) * cos($latB);
        $x = cos($latA) * sin($latB) - sin($latA) * cos($latB) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Shortest distance from $point to the great-circle segment $a-$b, using a
     * local equirectangular projection. At city scale the projection error is
     * negligible and it keeps the maths branch-free.
     *
     * @return array{distance: float, t: float, point: Coordinate}
     */
    public static function toSegment(Coordinate $point, Coordinate $a, Coordinate $b): array
    {
        $latRef = deg2rad(($a->lat + $b->lat) / 2);
        $mPerDegLat = 111_132.92 - 559.82 * cos(2 * $latRef) + 1.175 * cos(4 * $latRef);
        $mPerDegLng = 111_412.84 * cos($latRef) - 93.5 * cos(3 * $latRef);

        $ax = 0.0;
        $ay = 0.0;
        $bx = ($b->lng - $a->lng) * $mPerDegLng;
        $by = ($b->lat - $a->lat) * $mPerDegLat;
        $px = ($point->lng - $a->lng) * $mPerDegLng;
        $py = ($point->lat - $a->lat) * $mPerDegLat;

        $segLenSq = ($bx - $ax) ** 2 + ($by - $ay) ** 2;

        if ($segLenSq < 1e-9) {
            return ['distance' => self::between($point, $a), 't' => 0.0, 'point' => $a];
        }

        $t = max(0.0, min(1.0, (($px - $ax) * ($bx - $ax) + ($py - $ay) * ($by - $ay)) / $segLenSq));

        $projected = new Coordinate(
            $a->lat + ($b->lat - $a->lat) * $t,
            $a->lng + ($b->lng - $a->lng) * $t,
        );

        return ['distance' => self::between($point, $projected), 't' => $t, 'point' => $projected];
    }

    /**
     * Bounding box (in degrees) that contains every point within $meters.
     *
     * This is used as a SQL prefilter ahead of an exact haversine sort, so it
     * must never be too small — excluding a stop here would silently drop it
     * from "stops near me". Metres per degree of latitude varies from ~110.6 km
     * at the equator to ~111.7 km at the poles, so a flat divisor can
     * under-cover; the margin absorbs that and any rounding.
     */
    public static function boundingBox(Coordinate $center, float $meters): array
    {
        $meters *= 1.01;

        $latDelta = $meters / 110_570.0;
        $lngDelta = $meters / max(1.0, 111_320.0 * cos(deg2rad($center->lat)));

        return [
            'min_lat' => $center->lat - $latDelta,
            'max_lat' => $center->lat + $latDelta,
            'min_lng' => $center->lng - $lngDelta,
            'max_lng' => $center->lng + $lngDelta,
        ];
    }
}
