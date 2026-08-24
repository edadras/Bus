<?php

use App\Console\Commands\AuditLedgerCommand;
use App\Console\Commands\BillSchoolContractsCommand;
use App\Console\Commands\CloseAbandonedRidesCommand;
use App\Console\Commands\CloseStaleSchoolRunsCommand;
use App\Console\Commands\CloseStaleTaxiShiftsCommand;
use App\Console\Commands\CloseStaleTripsCommand;
use App\Console\Commands\NotifyArrivalsCommand;
use App\Console\Commands\PruneLocationDataCommand;
use App\Console\Commands\RollupMetricsCommand;
use App\Console\Commands\ScheduleSchoolRunsCommand;
use App\Domain\Payment\Services\TopupService;
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

// A taxi shift left open keeps a car on the passenger map that is not working
// and keeps its fare code live; a meter left running keeps billing. Hourly,
// because both are money.
Schedule::command(CloseStaleTaxiShiftsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// The school day is built before it starts, so a driver opening the app at
// dawn already has the morning's manifest. Idempotent per run, so the operator
// clicking the same button in the panel changes nothing.
Schedule::command(ScheduleSchoolRunsCommand::class)
    ->dailyAt('02:00')
    ->onOneServer();

// A run still open is a van still sharing its position with families. Checked
// often for that reason rather than for tidiness.
Schedule::command(CloseStaleSchoolRunsCommand::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Invoices for the period, and ageing the ones nobody paid. Issuing is
// idempotent on the period, so running twice cannot bill a family twice.
Schedule::command(BillSchoolContractsCommand::class)
    ->dailyAt('05:00')
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
    app(TopupService::class)->expireStale();
})->hourly()->name('payments:expire-stale')->onOneServer();

Schedule::command('auth:clear-resets')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
