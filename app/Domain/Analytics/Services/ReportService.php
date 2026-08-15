<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Support\Models\Complaint;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports.
 *
 * Every figure here is aggregated in SQL rather than pulled into PHP: these
 * queries run over the fact tables, and a report that loads a month of
 * boardings into memory stops working the moment the city gets busy.
 */
class ReportService
{
    /**
     * Transport performance: how much service ran, and how well.
     *
     * @return array<string, mixed>
     */
    public function transport(City $city, CarbonInterface $from, CarbonInterface $to): array
    {
        $trips = Trip::forCity($city)->whereBetween('started_at', [$from, $to]);

        $totals = (clone $trips)
            ->selectRaw('COUNT(*) as trips')
            ->selectRaw('COUNT(DISTINCT bus_id) as buses')
            ->selectRaw('COUNT(DISTINCT driver_id) as drivers')
            ->selectRaw('COALESCE(SUM(boarding_count), 0) as boardings')
            ->selectRaw('COALESCE(AVG(boarding_count), 0) as avg_passengers')
            ->selectRaw('AVG(average_speed_kmh) as avg_speed')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance')
            ->first();

        // Punctuality proxy: a trip that ran materially longer than its line's
        // nominal duration is late. Lines without a nominal duration are
        // excluded rather than counted as on time.
        $punctuality = (clone $trips)
            ->join('bus_lines', 'trips.bus_line_id', '=', 'bus_lines.id')
            ->whereNotNull('trips.ended_at')
            ->whereNotNull('bus_lines.typical_duration_minutes')
            ->selectRaw('COUNT(*) as measured')
            ->selectRaw(
                'SUM(CASE WHEN '.$this->durationMinutesExpression().
                ' > bus_lines.typical_duration_minutes * 1.2 THEN 1 ELSE 0 END) as late'
            )
            ->selectRaw(
                'AVG('.$this->durationMinutesExpression().' - bus_lines.typical_duration_minutes) as avg_delay'
            )
            ->first();

        return [
            'trips' => (int) $totals->trips,
            'buses_used' => (int) $totals->buses,
            'drivers_used' => (int) $totals->drivers,
            'boardings' => (int) $totals->boardings,
            'avg_passengers_per_trip' => round((float) $totals->avg_passengers, 2),
            'avg_speed_kmh' => $totals->avg_speed === null ? null : round((float) $totals->avg_speed, 1),
            'distance_meters' => (int) $totals->distance,
            'punctuality' => [
                'measured_trips' => (int) ($punctuality->measured ?? 0),
                'late_trips' => (int) ($punctuality->late ?? 0),
                'on_time_rate' => ($punctuality->measured ?? 0) > 0
                    ? round(1 - ($punctuality->late / $punctuality->measured), 3)
                    : null,
                'avg_delay_minutes' => $punctuality->avg_delay === null
                    ? null
                    : round((float) $punctuality->avg_delay, 1),
            ],
        ];
    }

    /**
     * Per-driver activity. Ordered by hours worked, which is the figure a
     * driver manager actually schedules against.
     *
     * @return array<int, array<string, mixed>>
     */
    public function drivers(City $city, CarbonInterface $from, CarbonInterface $to, int $limit = 100): array
    {
        $shifts = DriverShift::query()
            ->whereBetween('driver_shifts.started_at', [$from, $to])
            ->groupBy('driver_shifts.driver_id')
            ->selectRaw('driver_shifts.driver_id')
            ->selectRaw('COUNT(*) as shift_count')
            ->selectRaw('COALESCE(SUM(trip_count), 0) as trips')
            ->selectRaw('COALESCE(SUM(passenger_count), 0) as passengers')
            ->selectRaw('COALESCE(SUM(revenue_minor), 0) as revenue')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance')
            ->selectRaw('COALESCE(SUM('.$this->shiftMinutesExpression().'), 0) as minutes');

        $complaints = Complaint::query()
            ->whereNotNull('driver_id')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as complaint_count');

        return Driver::query()
            ->forCity($city)
            ->leftJoinSub($shifts, 's', 's.driver_id', '=', 'drivers.id')
            ->leftJoinSub($complaints, 'c', 'c.driver_id', '=', 'drivers.id')
            ->join('users', 'drivers.user_id', '=', 'users.id')
            ->select([
                'drivers.uuid',
                'drivers.employee_code',
                'drivers.status',
                'drivers.rating',
                'users.first_name',
                'users.last_name',
                'users.display_name',
            ])
            ->selectRaw('COALESCE(s.shift_count, 0) as shift_count')
            ->selectRaw('COALESCE(s.trips, 0) as trips')
            ->selectRaw('COALESCE(s.passengers, 0) as passengers')
            ->selectRaw('COALESCE(s.revenue, 0) as revenue')
            ->selectRaw('COALESCE(s.distance, 0) as distance_meters')
            ->selectRaw('COALESCE(s.minutes, 0) as active_minutes')
            ->selectRaw('COALESCE(c.complaint_count, 0) as complaints')
            ->orderByDesc('active_minutes')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'name' => trim($row->display_name ?: $row->first_name.' '.$row->last_name),
                'employee_code' => $row->employee_code,
                'status' => $row->status instanceof DriverStatus ? $row->status->value : $row->status,
                'shift_count' => (int) $row->shift_count,
                'trips' => (int) $row->trips,
                'passengers' => (int) $row->passengers,
                'revenue' => (int) $row->revenue,
                'distance_meters' => (int) $row->distance_meters,
                'active_minutes' => (int) $row->active_minutes,
                'complaints' => (int) $row->complaints,
                // Passengers carried per hour of service — the closest single
                // number to "how productive was this driver's time".
                'passengers_per_hour' => $row->active_minutes > 0
                    ? round($row->passengers / ($row->active_minutes / 60), 1)
                    : null,
            ])
            ->all();
    }

    /**
     * Ridership behaviour, aggregated. Deliberately contains no per-person
     * rows: this answers "how is the service used", not "who used it".
     *
     * @return array<string, mixed>
     */
    public function passengers(City $city, CarbonInterface $from, CarbonInterface $to): array
    {
        $rides = PassengerTrip::forCity($city)->whereBetween('boarded_at', [$from, $to]);

        $totals = (clone $rides)
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_users')
            ->selectRaw('COALESCE(SUM(fare_amount), 0) as fare_total')
            ->selectRaw('AVG(duration_seconds) as avg_duration')
            ->selectRaw('AVG(distance_meters) as avg_distance')
            ->first();

        $newUsers = User::where('city_id', $city->id)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // Registered accounts that rode at least once in the window.
        $activeUsers = (int) $totals->unique_users;

        // Ride counts bucketed by hour, which is what reveals the peaks a
        // frequency change would target.
        $byHour = (clone $rides)
            ->groupBy(DB::raw($this->hourExpression()))
            ->selectRaw($this->hourExpression().' as hour, COUNT(*) as rides')
            ->orderBy('hour')
            ->get()
            ->map(fn ($row) => ['hour' => (int) $row->hour, 'rides' => (int) $row->rides])
            ->all();

        // How many rides each rider took, bucketed — separates one-off riders
        // from regular commuters without naming anyone.
        $frequency = (clone $rides)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as ride_count')
            ->get()
            ->groupBy(fn ($row) => match (true) {
                $row->ride_count === 1 => 'once',
                $row->ride_count <= 5 => 'occasional',
                $row->ride_count <= 20 => 'regular',
                default => 'frequent',
            })
            ->map->count();

        return [
            'rides' => (int) $totals->rides,
            'active_users' => $activeUsers,
            'new_users' => $newUsers,
            'fare_total' => (int) $totals->fare_total,
            'avg_rides_per_user' => $activeUsers > 0
                ? round($totals->rides / $activeUsers, 2)
                : 0,
            'avg_duration_seconds' => $totals->avg_duration === null
                ? null
                : (int) round((float) $totals->avg_duration),
            'avg_distance_meters' => $totals->avg_distance === null
                ? null
                : (int) round((float) $totals->avg_distance),
            'by_hour' => $byHour,
            'frequency' => [
                'once' => (int) ($frequency['once'] ?? 0),
                'occasional' => (int) ($frequency['occasional'] ?? 0),
                'regular' => (int) ($frequency['regular'] ?? 0),
                'frequent' => (int) ($frequency['frequent'] ?? 0),
            ],
        ];
    }

    /**
     * Revenue by line and by bus — the two cuts a finance manager asks for.
     *
     * @return array<string, mixed>
     */
    public function revenue(City $city, CarbonInterface $from, CarbonInterface $to): array
    {
        $byLine = PassengerTrip::forCity($city)
            ->whereBetween('boarded_at', [$from, $to])
            ->join('bus_lines', 'passenger_trips.bus_line_id', '=', 'bus_lines.id')
            ->groupBy('bus_lines.id', 'bus_lines.code', 'bus_lines.name', 'bus_lines.color')
            ->selectRaw('bus_lines.id, bus_lines.code, bus_lines.name, bus_lines.color')
            ->selectRaw('COUNT(*) as rides, COALESCE(SUM(passenger_trips.fare_amount),0) as revenue')
            ->orderByDesc('revenue')
            ->get();

        $byBus = PassengerTrip::forCity($city)
            ->whereBetween('boarded_at', [$from, $to])
            ->join('buses', 'passenger_trips.bus_id', '=', 'buses.id')
            ->groupBy('buses.id', 'buses.bus_number')
            ->selectRaw('buses.id, buses.bus_number')
            ->selectRaw('COUNT(*) as rides, COALESCE(SUM(passenger_trips.fare_amount),0) as revenue')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();

        $byType = WalletTransaction::forCity($city)
            ->completed()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as count, COALESCE(SUM(amount),0) as total')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type instanceof TransactionType ? $row->type->value : $row->type,
                'count' => (int) $row->count,
                'total' => (int) $row->total,
            ]);

        return [
            'by_line' => $byLine,
            'by_bus' => $byBus,
            'by_transaction_type' => $byType,
        ];
    }

    /**
     * Portable SQL for a duration in minutes. SQLite (tests) has no
     * TIMESTAMPDIFF, so the expression is chosen per driver rather than
     * assuming MySQL.
     */
    private function durationMinutesExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '((julianday(trips.ended_at) - julianday(trips.started_at)) * 1440)'
            : 'TIMESTAMPDIFF(MINUTE, trips.started_at, trips.ended_at)';
    }

    private function shiftMinutesExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '((julianday(COALESCE(ended_at, started_at)) - julianday(started_at)) * 1440)'
            : 'TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, started_at))';
    }

    private function hourExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', boarded_at) AS INTEGER)"
            : 'HOUR(boarded_at)';
    }
}
