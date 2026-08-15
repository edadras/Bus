<?php

use App\Console\Commands\AuditLedgerCommand;
use App\Console\Commands\CloseAbandonedRidesCommand;
use App\Console\Commands\CloseStaleTripsCommand;
use App\Console\Commands\NotifyArrivalsCommand;
use App\Console\Commands\PruneLocationDataCommand;
use App\Console\Commands\RollupMetricsCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Everything here is safe to run concurrently on several app servers because
| each task takes a lock (withoutOverlapping / onOneServer).
|
*/

// Reap rides and trips abandoned by a dead phone. Frequent, because a stuck
// ride blocks the passenger's next boarding.
Schedule::command(CloseAbandonedRidesCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CloseStaleTripsCommand::class)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Arrival alerts are time critical; a late notification is a useless one.
Schedule::command(NotifyArrivalsCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Yesterday's rollup, plus a two-day backfill so a missed night self-heals.
Schedule::command(RollupMetricsCommand::class, ['--days=2'])
    ->dailyAt('00:20')
    ->onOneServer();

// Retention. Off-peak because it deletes in large chunks.
Schedule::command(PruneLocationDataCommand::class)
    ->dailyAt('03:30')
    ->onOneServer();

// Financial integrity check; failures surface in the scheduler's output.
Schedule::command(AuditLedgerCommand::class)
    ->dailyAt('04:00')
    ->onOneServer();

// Expire abandoned gateway payments so they cannot be completed much later.
Schedule::call(function (): void {
    app(\App\Domain\Payment\Services\TopupService::class)->expireStale();
})->hourly()->name('payments:expire-stale')->onOneServer();

Schedule::command('auth:clear-resets')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
