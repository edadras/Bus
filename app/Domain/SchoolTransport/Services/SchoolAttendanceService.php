<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Domain\SchoolTransport\Enums\SchoolTripStatus;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolTripStudent;
use App\Notifications\SchoolAttendanceNotification;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use Illuminate\Support\Facades\DB;

/**
 * Checking a child on and off the van.
 *
 * Every change is stamped with who made it and where they were, because these
 * rows are the answer to the only question that really matters here — "was my
 * child on the bus?" — and an answer nobody can be held to is not an answer.
 *
 * The guardian is told immediately. A parent who learns at four o'clock that
 * their child was marked absent at seven has been failed by the system, not by
 * the driver.
 */
class SchoolAttendanceService
{
    public function __construct(private readonly SchoolLiveService $live) {}

    public function pickUp(SchoolTripStudent $row, User $actor, ?Coordinate $at = null): SchoolTripStudent
    {
        $this->assertRunIsUnderWay($row);

        if ($row->status === SchoolAttendanceStatus::PickedUp) {
            return $row;
        }

        if ($row->status->isSettled()) {
            throw DomainException::make('child_already_settled', 409, [
                'status' => $row->status->value,
            ]);
        }

        return DB::transaction(function () use ($row, $actor, $at): SchoolTripStudent {
            $row->forceFill([
                'status' => SchoolAttendanceStatus::PickedUp,
                'picked_up_at' => now(),
                'picked_up_lat' => $at?->lat,
                'picked_up_lng' => $at?->lng,
                'recorded_by' => $actor->id,
            ])->save();

            $this->recount($row->trip);
            $this->notify($row, 'picked_up');

            return $row->fresh();
        });
    }

    public function dropOff(SchoolTripStudent $row, User $actor, ?Coordinate $at = null): SchoolTripStudent
    {
        $this->assertRunIsUnderWay($row);

        if ($row->status === SchoolAttendanceStatus::DroppedOff) {
            return $row;
        }

        if ($row->status !== SchoolAttendanceStatus::PickedUp) {
            // Setting down a child who was never picked up is a data error that
            // would quietly corrupt the one record parents rely on.
            throw DomainException::make('child_not_aboard', 409, [
                'status' => $row->status->value,
            ]);
        }

        return DB::transaction(function () use ($row, $actor, $at): SchoolTripStudent {
            $row->forceFill([
                'status' => SchoolAttendanceStatus::DroppedOff,
                'dropped_off_at' => now(),
                'dropped_off_lat' => $at?->lat,
                'dropped_off_lng' => $at?->lng,
                'recorded_by' => $actor->id,
            ])->save();

            $this->recount($row->trip);
            $this->notify($row, 'dropped_off');

            return $row->fresh();
        });
    }

    /** The child is not travelling today. */
    public function markAbsent(SchoolTripStudent $row, User $actor, ?string $note = null): SchoolTripStudent
    {
        if ($row->status === SchoolAttendanceStatus::PickedUp) {
            throw DomainException::make('child_is_aboard', 409);
        }

        return DB::transaction(function () use ($row, $actor, $note): SchoolTripStudent {
            $row->forceFill([
                'status' => SchoolAttendanceStatus::Absent,
                'recorded_by' => $actor->id,
                'note' => $note,
            ])->save();

            $this->recount($row->trip);
            $this->notify($row, 'absent');

            return $row->fresh();
        });
    }

    /**
     * A parent says in advance that their child is not travelling.
     *
     * Worth being a separate path from the driver marking absence: it is a
     * different claim by a different person, and the driver needs to see it
     * before they drive to the door.
     */
    public function reportAbsenceByGuardian(
        SchoolTripStudent $row,
        User $guardian,
        ?string $note = null,
    ): SchoolTripStudent {
        if ($row->student?->guardian_user_id !== $guardian->id) {
            throw DomainException::make('not_your_student', 403);
        }

        if ($row->status->isSettled() || $row->status === SchoolAttendanceStatus::PickedUp) {
            throw DomainException::make('child_already_settled', 409, [
                'status' => $row->status->value,
            ]);
        }

        $row->forceFill([
            'status' => SchoolAttendanceStatus::Absent,
            'recorded_by' => $guardian->id,
            'note' => $note ?? __('school.absence_reported_by_guardian'),
        ])->save();

        $this->recount($row->trip);

        return $row->fresh();
    }

    /** Undo a mistake, which is a thing that happens at a kerb in the rain. */
    public function reset(SchoolTripStudent $row, User $actor): SchoolTripStudent
    {
        $this->assertRunIsUnderWay($row);

        $row->forceFill([
            'status' => SchoolAttendanceStatus::Pending,
            'picked_up_at' => null,
            'dropped_off_at' => null,
            'recorded_by' => $actor->id,
        ])->save();

        $this->recount($row->trip);

        return $row->fresh();
    }

    private function assertRunIsUnderWay(SchoolTripStudent $row): void
    {
        if ($row->trip?->status !== SchoolTripStatus::InProgress) {
            throw DomainException::make('trip_not_live', 409, [
                'status' => $row->trip?->status->value,
            ]);
        }
    }

    private function recount(?SchoolTrip $trip): void
    {
        if ($trip === null) {
            return;
        }

        $counts = $trip->students()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $trip->forceFill([
            'picked_up_count' => (int) ($counts[SchoolAttendanceStatus::PickedUp->value] ?? 0),
            'dropped_off_count' => (int) ($counts[SchoolAttendanceStatus::DroppedOff->value] ?? 0),
            'absent_count' => (int) ($counts[SchoolAttendanceStatus::Absent->value] ?? 0)
                + (int) ($counts[SchoolAttendanceStatus::NoShow->value] ?? 0),
        ])->save();

        if ($trip->status === SchoolTripStatus::InProgress) {
            $this->live->publish($trip->fresh(['route', 'vehicle']));
        }
    }

    private function notify(SchoolTripStudent $row, string $event): void
    {
        $guardian = $row->student?->guardian;

        if ($guardian === null) {
            return;
        }

        $guardian->notify(new SchoolAttendanceNotification(
            studentName: $row->student->name,
            event: $event,
            at: now(),
            tripUuid: $row->trip?->uuid ?? '',
        ));
    }
}
