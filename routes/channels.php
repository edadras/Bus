<?php

use App\Domain\Identity\Models\User;
use App\Domain\Operations\Models\Trip;
use App\Domain\Taxi\Models\TaxiShift;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Public city and trip channels carry only what any passenger may see: bus
| position, line, occupancy ratio. Anything that identifies a person — the
| driver's name, a passenger's wallet — lives on a private channel with an
| explicit authorisation callback below.
|
*/

$prefix = config('transit.live.channel_prefix', 'transit');

/** Crew channel: the driver of this trip, plus operations staff. */
Broadcast::channel($prefix.'.trip.{tripId}.crew', function (User $user, int $tripId) {
    $trip = Trip::find($tripId);

    if ($trip === null) {
        return false;
    }

    if ($trip->driver?->user_id === $user->id) {
        return true;
    }

    $user->loadMissing('roles.permissions');

    return $user->hasPermission('operations.live_map') && $user->canAccessCity($trip->city_id);
});

/** A user's own wallet. Nobody else, ever — including administrators. */
Broadcast::channel('wallet.user.{userId}', fn (User $user, int $userId) => $user->id === $userId);

/** A passenger's own ride updates. */
Broadcast::channel('rides.user.{userId}', fn (User $user, int $userId) => $user->id === $userId);

/**
 * A taxi driver's own shift.
 *
 * This is where a fare landing is announced, so it carries money the driver is
 * owed: only the driver working that shift, and operations staff for the city
 * it belongs to.
 */
Broadcast::channel($prefix.'.taxi.shift.{shiftId}', function (User $user, int $shiftId) {
    $shift = TaxiShift::find($shiftId);

    if ($shift === null) {
        return false;
    }

    if ($shift->driver?->user_id === $user->id) {
        return true;
    }

    $user->loadMissing('roles.permissions');

    return $user->hasPermission('operations.live_map') && $user->canAccessCity($shift->city_id);
});

/** Operations room: full fleet detail for one city. */
Broadcast::channel($prefix.'.control.{cityId}', function (User $user, int $cityId) {
    $user->loadMissing('roles.permissions');

    return $user->hasPermission('operations.live_map') && $user->canAccessCity($cityId);
});
