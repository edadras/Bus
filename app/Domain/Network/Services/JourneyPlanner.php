<?php

namespace App\Domain\Network\Services;

use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Journey planning across the network, with transfers.
 *
 * The network is modelled on one scalar axis — `route_stops.distance_from_start`
 * — which is what makes this tractable without a general graph library. A ride
 * is "board at sequence i, alight at sequence j > i on the same route", and the
 * distance travelled is the difference of two integers already stored.
 *
 * The search is a labelled frontier, relaxed once per permitted ride:
 *
 *   round 0   stops within walking distance of the origin, cost = walk time
 *   round n   every stop reachable by one more ride from the round n-1 frontier,
 *             plus the interchange walk and the wait for the next bus
 *
 * After every round the destination stops are checked against the frontier, so
 * a one-bus journey is found before a two-bus one and the cheaper of the two
 * always wins on total time rather than on leg count.
 *
 * The single thing that makes this produce sane answers is that **cost is
 * carried, not recomputed**. A label is the whole journey so far — walking to
 * the first stop included — so choosing between two ways of reaching the same
 * stop compares like with like. Collapsing on ride time alone would happily
 * pick a shorter ride that starts at a stop ten minutes' walk away.
 *
 * Cost is wall-clock minutes as the passenger experiences them: walking at
 * 5 km/h, riding at the route baseline, plus an expected wait of half the
 * line's headway at every boarding. The wait is what actually makes a transfer
 * expensive, and a planner that ignores it recommends journeys that feel much
 * worse than they score.
 */
class JourneyPlanner
{
    /** Metres per minute on foot — 5 km/h, the standard planning assumption. */
    private const WALK_SPEED = 83.0;

    /** How far a passenger will walk between two stops to change bus. */
    private const TRANSFER_WALK_METERS = 300;

    /** Assumed headway when a line does not declare one. */
    private const DEFAULT_HEADWAY_MINUTES = 15;

    /** Flat cost of changing bus, on top of the wait: finding the stop, crossing. */
    private const TRANSFER_PENALTY_MINUTES = 3;

    /**
     * How many stops a frontier may carry into the next round.
     *
     * Without this the second relaxation joins every stop in the city against
     * every stop downstream of it. The cap is on the cheapest labels, so what
     * gets dropped is the far end of the city — which was never going to be
     * part of a sensible answer anyway.
     */
    private const FRONTIER_LIMIT = 250;

    private const MAX_OPTIONS = 5;

    /** How many stops around each endpoint are considered walkable. */
    private const ENDPOINT_STOP_LIMIT = 25;

    /**
     * @return array{
     *     options: array<int, array<string, mixed>>,
     *     reason: string|null,
     *     supports_transfers: bool,
     *     max_transfers: int
     * }
     */
    public function plan(
        City $city,
        Coordinate $from,
        Coordinate $to,
        int $walkRadius = 700,
        int $maxTransfers = 2,
    ): array {
        $originStops = $this->stopsNear($city, $from, $walkRadius);
        $destinationStops = $this->stopsNear($city, $to, $walkRadius);

        $directWalk = Distance::between($from, $to);
        $walkMinutes = (int) ceil($directWalk / self::WALK_SPEED);

        if ($originStops->isEmpty() || $destinationStops->isEmpty()) {
            // Walking is still an answer, and often the right one when the
            // reason there is no stop nearby is that the trip is 300 metres.
            return $this->result(
                $directWalk <= $walkRadius ? [$this->walkOnlyOption($directWalk, $walkMinutes)] : [],
                'no_stop_within_walking_distance',
                $maxTransfers,
            );
        }

        // Cost of getting off the network at each destination stop.
        $egress = [];
        foreach ($destinationStops as $stop) {
            $walk = Distance::between($to, $stop->coordinate());
            $egress[$stop->id] = ['minutes' => $walk / self::WALK_SPEED, 'meters' => $walk];
        }

        // Round 0: standing at a stop near the origin, having walked there.
        $frontier = [];
        foreach ($originStops as $stop) {
            $walk = Distance::between($from, $stop->coordinate());
            $frontier[$stop->id] = [
                'minutes' => $walk / self::WALK_SPEED,
                'legs' => [],
                'access_meters' => $walk,
                'transfer_meters' => 0.0,
            ];
        }

        $options = [];

        for ($ride = 0; $ride <= $maxTransfers; $ride++) {
            $frontier = $this->rideOnce($city, $frontier);

            if ($frontier === []) {
                break;
            }

            foreach ($this->arrivalsAt($frontier, $egress) as $option) {
                $options[] = $option;
            }

            if ($ride < $maxTransfers) {
                // Prune against the best complete journey so far: every
                // remaining term is positive, so a label already slower than a
                // finished option cannot become part of a better one. With no
                // option yet there is nothing to prune against — and the
                // search must continue regardless, because needing a change is
                // exactly the case where the first round finds nothing.
                $frontier = $this->prepareTransfer(
                    $frontier,
                    $options === [] ? INF : min(array_column($options, 'estimated_total_minutes')),
                );
            }
        }

        $options = $this->bestPerLineCombination($options);

        usort($options, fn ($a, $b) => $a['estimated_total_minutes'] <=> $b['estimated_total_minutes']);

        $options = array_slice($options, 0, self::MAX_OPTIONS);

        // Offer the walk only when it genuinely wins; otherwise it is noise.
        if ($directWalk <= $walkRadius * 2
            && ($options === [] || $walkMinutes <= $options[0]['estimated_total_minutes'])) {
            array_unshift($options, $this->walkOnlyOption($directWalk, $walkMinutes));
        }

        return $this->result($options, $options === [] ? 'no_route_found' : null, $maxTransfers);
    }

    // ── Search ───────────────────────────────────────────────────────────

    /**
     * Relax the frontier by exactly one ride.
     *
     * One query, not one per stop: `route_stops` is joined to itself on the
     * same route with a later sequence, which is the whole "you may ride
     * forwards along a route" rule expressed once.
     *
     * @param  array<int, array<string, mixed>>  $frontier  keyed by stop id
     * @return array<int, array<string, mixed>> keyed by the stop alighted at
     */
    private function rideOnce(City $city, array $frontier): array
    {
        if ($frontier === []) {
            return [];
        }

        $rows = DB::table('route_stops as boarding')
            ->join('route_stops as alighting', function ($join): void {
                $join->on('alighting.route_id', '=', 'boarding.route_id')
                    ->whereColumn('alighting.sequence', '>', 'boarding.sequence');
            })
            ->join('routes', 'routes.id', '=', 'boarding.route_id')
            ->join('bus_lines', 'bus_lines.id', '=', 'routes.bus_line_id')
            ->join('bus_stops as board_stop', 'board_stop.id', '=', 'boarding.bus_stop_id')
            ->join('bus_stops as alight_stop', 'alight_stop.id', '=', 'alighting.bus_stop_id')
            ->whereIn('boarding.bus_stop_id', array_keys($frontier))
            ->where('bus_lines.city_id', $city->id)
            ->where('bus_lines.is_active', true)
            ->where('routes.is_active', true)
            ->where('boarding.allows_boarding', true)
            ->where('alighting.allows_alighting', true)
            ->select([
                'boarding.bus_stop_id as board_stop_id',
                'alighting.bus_stop_id as alight_stop_id',
                'board_stop.name as board_stop_name',
                'alight_stop.name as alight_stop_name',
                'boarding.sequence as board_sequence',
                'alighting.sequence as alight_sequence',
                'boarding.distance_from_start as board_distance',
                'alighting.distance_from_start as alight_distance',
                'routes.id as route_id',
                'bus_lines.id as line_id',
                'bus_lines.code as line_code',
                'bus_lines.name as line_name',
                'bus_lines.color as line_color',
                'bus_lines.headway_minutes as headway_minutes',
            ])
            ->get();

        $reached = [];

        foreach ($rows as $row) {
            $label = $frontier[(int) $row->board_stop_id];
            $leg = $this->legFromRow($row);

            // Riding the line you just got off is not a journey, it is a loop.
            $previous = $label['legs'] === [] ? null : end($label['legs']);

            if ($previous !== null && $previous['line']['id'] === $leg['line']['id']) {
                continue;
            }

            $stopId = (int) $row->alight_stop_id;
            $minutes = $label['minutes'] + $leg['ride_minutes'];

            if (isset($reached[$stopId]) && $reached[$stopId]['minutes'] <= $minutes) {
                continue;
            }

            $reached[$stopId] = [
                'minutes' => $minutes,
                'legs' => [...$label['legs'], $leg],
                'access_meters' => $label['access_meters'],
                'transfer_meters' => $label['transfer_meters'],
            ];
        }

        return $reached;
    }

    /**
     * Turn labels that have reached a destination stop into journey options.
     *
     * @param  array<int, array<string, mixed>>  $frontier
     * @param  array<int, array{minutes: float, meters: float}>  $egress
     * @return array<int, array<string, mixed>>
     */
    private function arrivalsAt(array $frontier, array $egress): array
    {
        $options = [];

        foreach ($egress as $stopId => $exit) {
            $label = $frontier[$stopId] ?? null;

            if ($label === null) {
                continue;
            }

            $options[] = $this->buildOption($label, $exit['meters']);
        }

        return $options;
    }

    /**
     * Move a frontier of alighting stops to a frontier of boarding stops for
     * the next ride: pay the transfer penalty, and allow a short walk to a
     * nearby stop, which is how an interchange actually works — opposite sides
     * of a road, separate bays of one terminal.
     *
     * @param  array<int, array<string, mixed>>  $frontier
     * @return array<int, array<string, mixed>>
     */
    private function prepareTransfer(array $frontier, float $bestSoFar): array
    {
        // Anything already slower than a complete journey cannot become part
        // of a better one — every remaining term is positive.
        $frontier = array_filter($frontier, fn (array $label) => $label['minutes'] < $bestSoFar);

        uasort($frontier, fn ($a, $b) => $a['minutes'] <=> $b['minutes']);

        $frontier = array_slice($frontier, 0, self::FRONTIER_LIMIT, true);

        $next = [];

        foreach ($frontier as $stopId => $label) {
            $next[$stopId] = [
                ...$label,
                'minutes' => $label['minutes'] + self::TRANSFER_PENALTY_MINUTES,
            ];
        }

        foreach ($this->walkableNeighbours(array_keys($next)) as $from => $neighbours) {
            $label = $next[$from];

            foreach ($neighbours as $to => $meters) {
                $minutes = $label['minutes'] + $meters / self::WALK_SPEED;

                if (isset($next[$to]) && $next[$to]['minutes'] <= $minutes) {
                    continue;
                }

                $next[$to] = [
                    ...$label,
                    'minutes' => $minutes,
                    'transfer_meters' => $label['transfer_meters'] + $meters,
                ];
            }
        }

        return $next;
    }

    /**
     * Stops within interchange walking distance of the given ones.
     *
     * A bounding box narrows the candidates in SQL; the exact distance is then
     * computed in PHP, because a box is a square and a walking radius is not.
     *
     * @param  array<int, int>  $stopIds
     * @return array<int, array<int, float>>
     */
    private function walkableNeighbours(array $stopIds): array
    {
        if ($stopIds === []) {
            return [];
        }

        $origins = BusStop::whereIn('id', $stopIds)->get(['id', 'city_id', 'lat', 'lng']);

        if ($origins->isEmpty()) {
            return [];
        }

        $margin = self::TRANSFER_WALK_METERS / 111_320;

        $candidates = BusStop::query()
            ->where('city_id', $origins->first()->city_id)
            ->where('is_active', true)
            ->whereBetween('lat', [$origins->min('lat') - $margin, $origins->max('lat') + $margin])
            ->whereBetween('lng', [$origins->min('lng') - $margin * 2, $origins->max('lng') + $margin * 2])
            ->get(['id', 'lat', 'lng']);

        $neighbours = [];

        foreach ($origins as $origin) {
            foreach ($candidates as $candidate) {
                if ($candidate->id === $origin->id) {
                    continue;
                }

                $meters = Distance::between($origin->coordinate(), $candidate->coordinate());

                if ($meters <= self::TRANSFER_WALK_METERS) {
                    $neighbours[$origin->id][$candidate->id] = $meters;
                }
            }
        }

        return $neighbours;
    }

    // ── Presentation ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function legFromRow(object $row): array
    {
        $distance = max(0, (int) $row->alight_distance - (int) $row->board_distance);
        $headway = (int) ($row->headway_minutes ?: self::DEFAULT_HEADWAY_MINUTES);

        return [
            'line' => [
                'id' => (int) $row->line_id,
                'code' => $row->line_code,
                'name' => $row->line_name,
                'color' => $row->line_color,
            ],
            'route_id' => (int) $row->route_id,
            'board_at' => ['id' => (int) $row->board_stop_id, 'name' => $row->board_stop_name],
            'alight_at' => ['id' => (int) $row->alight_stop_id, 'name' => $row->alight_stop_name],
            'stops_count' => (int) $row->alight_sequence - (int) $row->board_sequence,
            'ride_distance_meters' => $distance,
            'headway_minutes' => $headway,
            // Half the headway is the expected wait for a service running at
            // that frequency, and it is the term that makes a change costly.
            'ride_minutes' => $this->rideMinutes($distance) + $headway / 2,
        ];
    }

    /**
     * @param  array<string, mixed>  $label
     * @return array<string, mixed>
     */
    private function buildOption(array $label, float $egressMeters): array
    {
        $legs = $label['legs'];
        $transfers = count($legs) - 1;

        $walkMeters = $label['access_meters'] + $label['transfer_meters'] + $egressMeters;

        // The total is built from the parts the client will display, not from
        // the float the search ranked on. A passenger who adds up "6 min walk
        // + 9 min + 12 min" and gets a different number than the headline has
        // caught the app lying, however small the discrepancy.
        $presentedLegs = array_map(
            static fn (array $leg) => [...$leg, 'ride_minutes' => (int) ceil($leg['ride_minutes'])],
            $legs,
        );

        $walkMinutes = (int) ceil($walkMeters / self::WALK_SPEED);
        $penalty = $transfers * self::TRANSFER_PENALTY_MINUTES;
        $total = $walkMinutes + $penalty + array_sum(array_column($presentedLegs, 'ride_minutes'));

        return [
            'mode' => 'bus',
            'transfers' => $transfers,
            'legs' => $presentedLegs,
            // Also flattened, because the first release's clients read these.
            'line' => $legs[0]['line'],
            'route_id' => $legs[0]['route_id'],
            'board_at' => $legs[0]['board_at'],
            'alight_at' => $legs[count($legs) - 1]['alight_at'],
            'stops_count' => array_sum(array_column($legs, 'stops_count')),
            'ride_distance_meters' => array_sum(array_column($legs, 'ride_distance_meters')),
            'walk_to_stop_meters' => (int) round($label['access_meters']),
            'walk_from_stop_meters' => (int) round($egressMeters),
            'transfer_walk_meters' => (int) round($label['transfer_meters']),
            'total_walk_meters' => (int) round($walkMeters),
            'walk_minutes' => $walkMinutes,
            'transfer_penalty_minutes' => $penalty,
            'estimated_total_minutes' => $total,
        ];
    }

    /** @return array<string, mixed> */
    private function walkOnlyOption(float $meters, int $minutes): array
    {
        return [
            'mode' => 'walk',
            'transfers' => 0,
            'legs' => [],
            'line' => null,
            'route_id' => null,
            'board_at' => null,
            'alight_at' => null,
            'stops_count' => 0,
            'ride_distance_meters' => 0,
            'walk_to_stop_meters' => 0,
            'walk_from_stop_meters' => 0,
            'transfer_walk_meters' => 0,
            'total_walk_meters' => (int) round($meters),
            'walk_minutes' => $minutes,
            'transfer_penalty_minutes' => 0,
            'estimated_total_minutes' => $minutes,
        ];
    }

    /**
     * Keep one option per sequence of lines. Two routes of the same line, or
     * two boarding stops on the same corner, are the same journey to a
     * passenger and should not fill the list.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, array<string, mixed>>
     */
    private function bestPerLineCombination(array $options): array
    {
        $best = [];

        foreach ($options as $option) {
            $key = implode('>', array_map(
                static fn (array $leg) => $leg['line']['id'],
                $option['legs'],
            ));

            if (! isset($best[$key])
                || $option['estimated_total_minutes'] < $best[$key]['estimated_total_minutes']) {
                $best[$key] = $option;
            }
        }

        return array_values($best);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function rideMinutes(int $meters): float
    {
        $metresPerMinute = ((float) config('transit.eta.baseline_speed_kmh', 22)) * 1000 / 60;

        return $meters / max(1.0, $metresPerMinute);
    }

    /** @return Collection<int, BusStop> */
    private function stopsNear(City $city, Coordinate $center, int $radius): Collection
    {
        return BusStop::query()
            ->forCity($city)
            ->active()
            ->near($center, $radius)
            ->limit(self::ENDPOINT_STOP_LIMIT)
            ->get()
            ->filter(fn (BusStop $stop) => $stop->distanceTo($center) <= $radius)
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array{options: array<int, array<string, mixed>>, reason: string|null, supports_transfers: bool, max_transfers: int}
     */
    private function result(array $options, ?string $reason, int $maxTransfers): array
    {
        return [
            'options' => $options,
            'reason' => $options === [] ? $reason : null,
            'supports_transfers' => true,
            'max_transfers' => $maxTransfers,
        ];
    }
}
