<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\DailyMetric;
use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\LiveStateStore;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Support\Models\Complaint;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard aggregation.
 *
 * Today's numbers are computed live (they change constantly and the window is
 * small); anything historical is read from daily_metrics, which the nightly
 * rollup fills. That split keeps the dashboard fast without ever showing a
 * stale figure for the current day.
 */
class DashboardService
{
    public function __construct(private readonly LiveStateStore $liveState) {}

    /** @return array<string, mixed> */
    public function kpis(City $city): array
    {
        return Cache::remember("dashboard:kpi:{$city->id}", 30, function () use ($city) {
            $today = now()->startOfDay();
            $live = $this->liveState->forCity($city->id);

            $todayTrips = Trip::forCity($city)->where('started_at', '>=', $today);
            $todayRides = PassengerTrip::forCity($city)->where('boarded_at', '>=', $today);

            $money = WalletTransaction::forCity($city)
                ->completed()
                ->where('created_at', '>=', $today)
                ->selectRaw('COUNT(*) as cnt')
                ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as fares", [TransactionType::FarePayment->value])
                ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as topups", [TransactionType::Topup->value])
                ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as merchant", [TransactionType::MerchantPayment->value])
                ->first();

            return [
                'buses_moving' => count($live),
                'passengers_on_board' => array_sum(array_column($live, 'passenger_count')),
                'active_buses' => Bus::forCity($city)->deployable()->count(),
                'total_buses' => Bus::forCity($city)->count(),
                'active_drivers' => Driver::forCity($city)->where('status', DriverStatus::Active->value)->count(),
                'drivers_on_shift' => Trip::forCity($city)->live()->distinct('driver_id')->count('driver_id'),
                'trips_today' => (clone $todayTrips)->count(),
                'boardings_today' => (clone $todayRides)->count(),
                'unique_passengers_today' => (clone $todayRides)->distinct('user_id')->count('user_id'),
                'transactions_today' => (int) ($money->cnt ?? 0),
                'fare_revenue_today' => (int) ($money->fares ?? 0),
                'topup_amount_today' => (int) ($money->topups ?? 0),
                'merchant_volume_today' => (int) ($money->merchant ?? 0),
                'open_complaints' => Complaint::forCity($city)->open()->count(),
                'complaints_today' => Complaint::forCity($city)->where('created_at', '>=', $today)->count(),
                'lines' => BusLine::forCity($city)->active()->count(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * A time series for the dashboard charts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function series(City $city, string $range = 'week'): array
    {
        [$from, $to] = $this->rangeBounds($range);

        $metrics = DailyMetric::forCity($city)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn (DailyMetric $m) => $m->date->toDateString());

        $series = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $metric = $metrics->get($key);

            // The current day is not in the rollup yet, so compute it live.
            if ($metric === null && $cursor->isToday()) {
                $metric = $this->computeForDay($city, $cursor);
            }

            $series[] = [
                'date' => $key,
                'trips' => $metric?->trips_count ?? 0,
                'boardings' => $metric?->boardings_count ?? 0,
                'unique_passengers' => $metric?->unique_passengers ?? 0,
                'fare_revenue' => $metric?->fare_revenue ?? 0,
                'topup_amount' => $metric?->topup_amount ?? 0,
                'merchant_volume' => $metric?->merchant_volume ?? 0,
                'transactions' => $metric?->transactions_count ?? 0,
                'complaints' => $metric?->complaints_opened ?? 0,
                'avg_passengers_per_trip' => round((float) ($metric?->avg_passengers_per_trip ?? 0), 2),
                'avg_speed_kmh' => $metric?->avg_speed_kmh,
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /** Busiest and quietest lines over a window. */
    public function lineRanking(City $city, string $range = 'week', int $limit = 10): array
    {
        [$from, $to] = $this->rangeBounds($range);

        $rows = PassengerTrip::forCity($city)
            ->whereBetween('boarded_at', [$from->startOfDay(), $to->endOfDay()])
            ->join('bus_lines', 'passenger_trips.bus_line_id', '=', 'bus_lines.id')
            ->groupBy('bus_lines.id', 'bus_lines.code', 'bus_lines.name', 'bus_lines.color')
            ->selectRaw('bus_lines.id, bus_lines.code, bus_lines.name, bus_lines.color')
            ->selectRaw('COUNT(*) as boardings')
            ->selectRaw('COALESCE(SUM(passenger_trips.fare_amount),0) as revenue')
            ->orderByDesc('boardings')
            ->limit($limit)
            ->get();

        return [
            'busiest' => $rows->values()->all(),
            'quietest' => $rows->sortBy('boardings')->take(5)->values()->all(),
        ];
    }

    public function stopRanking(City $city, string $range = 'week', int $limit = 10): array
    {
        [$from, $to] = $this->rangeBounds($range);

        return PassengerTrip::forCity($city)
            ->whereBetween('boarded_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotNull('boarding_stop_id')
            ->join('bus_stops', 'passenger_trips.boarding_stop_id', '=', 'bus_stops.id')
            ->groupBy('bus_stops.id', 'bus_stops.name', 'bus_stops.code')
            ->selectRaw('bus_stops.id, bus_stops.name, bus_stops.code, COUNT(*) as boardings')
            ->orderByDesc('boardings')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** Roll one day's facts into daily_metrics. Idempotent. */
    public function rollup(City $city, CarbonInterface $date): DailyMetric
    {
        $metric = $this->computeForDay($city, Carbon::instance($date));
        $metric->save();

        return $metric;
    }

    private function computeForDay(City $city, Carbon $date): DailyMetric
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $trips = Trip::forCity($city)->whereBetween('started_at', [$start, $end]);
        $rides = PassengerTrip::forCity($city)->whereBetween('boarded_at', [$start, $end]);

        $tripStats = (clone $trips)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(AVG(boarding_count),0) as avg_passengers')
            ->selectRaw('AVG(average_speed_kmh) as avg_speed')
            ->selectRaw('COUNT(DISTINCT bus_id) as buses')
            ->selectRaw('COUNT(DISTINCT driver_id) as drivers')
            ->first();

        $money = WalletTransaction::forCity($city)
            ->completed()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as fares", [TransactionType::FarePayment->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as topups", [TransactionType::Topup->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as merchant", [TransactionType::MerchantPayment->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END),0) as refunds", [TransactionType::Reversal->value])
            ->first();

        $metric = DailyMetric::firstOrNew([
            'city_id' => $city->id,
            'date' => $date->toDateString(),
        ]);

        $metric->fill([
            'trips_count' => (int) $tripStats->cnt,
            'active_buses' => (int) $tripStats->buses,
            'active_drivers' => (int) $tripStats->drivers,
            'boardings_count' => (clone $rides)->count(),
            'unique_passengers' => (clone $rides)->distinct('user_id')->count('user_id'),
            'new_users' => User::whereBetween('created_at', [$start, $end])
                ->where('city_id', $city->id)->count(),
            'fare_revenue' => (int) $money->fares,
            'topup_amount' => (int) $money->topups,
            'merchant_volume' => (int) $money->merchant,
            'refund_amount' => (int) $money->refunds,
            'transactions_count' => (int) $money->cnt,
            'complaints_opened' => Complaint::forCity($city)->whereBetween('created_at', [$start, $end])->count(),
            'complaints_resolved' => Complaint::forCity($city)->whereBetween('resolved_at', [$start, $end])->count(),
            'avg_passengers_per_trip' => round((float) $tripStats->avg_passengers, 2),
            'avg_speed_kmh' => $tripStats->avg_speed === null ? null : round((float) $tripStats->avg_speed, 2),
        ]);

        return $metric;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangeBounds(string $range): array
    {
        $to = now();

        $from = match ($range) {
            'today' => now()->startOfDay(),
            'week' => now()->subDays(6)->startOfDay(),
            'month' => now()->subDays(29)->startOfDay(),
            'year' => now()->subMonths(11)->startOfMonth(),
            default => now()->subDays(6)->startOfDay(),
        };

        return [$from, $to];
    }
}
