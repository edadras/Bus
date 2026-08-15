<?php

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DriverAdminController;
use App\Http\Controllers\Api\V1\Admin\FinanceController;
use App\Http\Controllers\Api\V1\Admin\FleetController;
use App\Http\Controllers\Api\V1\Admin\MerchantAdminController;
use App\Http\Controllers\Api\V1\Admin\NetworkAdminController;
use App\Http\Controllers\Api\V1\Admin\OccupancyController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\SupportAdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\NetworkController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PassengerController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned from the outset so a breaking change can ship as /v2 while the
| installed base of mobile apps keeps working against /v1.
|
| Middleware groups in play (applied globally in bootstrap/app.php):
|   EnsureJsonResponse  - forces JSON negotiation
|   ResolveLocale       - fa by default, RTL aware
|   ResolveTenantCity   - binds the City for the request
|
| Sanctum abilities gate the four client surfaces: passenger, driver,
| merchant and admin. A passenger token literally cannot call a driver route.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
    | Public — usable without an account. A passenger must be able to look up
    | a stop and see when the next bus arrives before signing up.
    */
    Route::middleware('throttle:public')->group(function (): void {
        Route::get('map/config', [NetworkController::class, 'mapConfig']);

        Route::get('stops', [NetworkController::class, 'stops']);
        Route::get('stops/{stop}', [NetworkController::class, 'stop']);
        Route::get('stops/{stop}/arrivals', [NetworkController::class, 'arrivals']);

        Route::get('lines', [NetworkController::class, 'lines']);
        Route::get('lines/{line}', [NetworkController::class, 'line']);
        Route::get('routes/{route}', [NetworkController::class, 'route']);
        Route::get('journey/plan', [NetworkController::class, 'plan']);

        Route::get('buses/live', [LiveController::class, 'buses']);
        Route::get('trips/{trip}', [LiveController::class, 'trip']);
        Route::get('trips/{trip}/eta/{stop}', [LiveController::class, 'tripEta']);
        Route::get('summary', [LiveController::class, 'summary']);

        // The VAPID public key is, by design, public: a browser needs it
        // before it can create a subscription at all.
        Route::get('push/key', [PushSubscriptionController::class, 'key']);
    });

    /*
    | Authentication
    */
    Route::prefix('auth')->group(function (): void {
        Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    /*
    | Any signed-in client, whichever surface it came from. A driver wants
    | shift alerts and a merchant wants settlement alerts just as much as a
    | passenger wants an arrival alert, so this is not ability-gated.
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::prefix('push')->group(function (): void {
            Route::post('subscriptions', [PushSubscriptionController::class, 'store']);
            Route::delete('subscriptions', [PushSubscriptionController::class, 'destroy']);
        });

        // The in-app inbox. Every notification lands here, whether or not it
        // also went out as a push or an SMS.
        Route::prefix('notifications')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('read-all', [NotificationController::class, 'markAllRead']);
            Route::post('{notification}/read', [NotificationController::class, 'markRead']);
        });
    });

    /*
    | Passenger — requires the `passenger` ability.
    */
    Route::middleware(['auth:sanctum', 'abilities:passenger'])->group(function (): void {

        Route::prefix('wallet')->group(function (): void {
            Route::get('/', [WalletController::class, 'show']);
            Route::get('transactions', [WalletController::class, 'transactions']);
            Route::post('topup', [WalletController::class, 'topup'])->middleware('throttle:financial');
            Route::get('payments/{payment}', [WalletController::class, 'paymentStatus']);
        });

        // Boarding is the single most abused endpoint, hence the tight limit
        // on top of the per-user checks inside BoardingService.
        Route::post('bus/scan', [PassengerController::class, 'scan'])->middleware('throttle:financial');
        Route::get('rides/active', [PassengerController::class, 'activeRide']);
        Route::post('rides/{passengerTrip}/ping', [PassengerController::class, 'ping'])
            ->middleware('throttle:telemetry');
        Route::post('rides/{passengerTrip}/end', [PassengerController::class, 'endRide']);
        Route::get('rides', [PassengerController::class, 'history']);
        Route::get('fare/quote', [PassengerController::class, 'fareQuote']);

        Route::post('merchant/charge', [MerchantController::class, 'charge'])->middleware('throttle:financial');

        Route::prefix('complaints')->group(function (): void {
            Route::get('categories', [ComplaintController::class, 'categories']);
            Route::get('/', [ComplaintController::class, 'index']);
            Route::post('/', [ComplaintController::class, 'store'])->middleware('throttle:uploads');
            Route::get('{complaint}', [ComplaintController::class, 'show']);
            Route::post('{complaint}/reply', [ComplaintController::class, 'reply']);
            Route::post('{complaint}/rate', [ComplaintController::class, 'rate']);
        });
    });

    /*
    | Driver — requires the `driver` ability, which is only ever minted for an
    | approved driver record.
    */
    Route::prefix('driver')->middleware(['auth:sanctum', 'abilities:driver'])->group(function (): void {
        Route::get('state', [DriverController::class, 'state']);

        Route::post('shifts/start', [DriverController::class, 'startShift']);
        Route::post('shifts/end', [DriverController::class, 'endShift']);

        Route::post('trips/start', [DriverController::class, 'startTrip']);
        Route::post('trips/pause', [DriverController::class, 'pauseTrip']);
        Route::post('trips/resume', [DriverController::class, 'resumeTrip']);
        Route::post('trips/complete', [DriverController::class, 'completeTrip']);

        Route::post('location', [DriverController::class, 'location'])->middleware('throttle:telemetry');
        Route::get('passengers', [DriverController::class, 'passengers']);
        Route::get('route', [DriverController::class, 'currentRoute']);
    });

    /*
    | Merchant — the till side of the wallet.
    */
    Route::prefix('merchant')->middleware(['auth:sanctum', 'abilities:merchant'])->group(function (): void {
        Route::get('state', [MerchantController::class, 'state']);
        Route::get('terminals/{terminal}/token', [MerchantController::class, 'terminalToken']);
        Route::get('transactions', [MerchantController::class, 'transactions']);
        Route::post('transactions/{merchantTransaction}/refund', [MerchantController::class, 'refund'])
            ->middleware('throttle:financial');
        Route::get('reports/sales', [MerchantController::class, 'salesReport']);
        Route::post('settlements/request', [MerchantController::class, 'requestSettlement']);
    });

    /*
    | Admin — the `admin` ability plus a per-endpoint permission check, so a
    | support agent cannot reach finance and a city operator cannot reach
    | another city's fleet.
    */
    Route::prefix('admin')->middleware(['auth:sanctum', 'abilities:admin'])->group(function (): void {

        Route::middleware('permission:dashboard.view')->group(function (): void {
            Route::get('dashboard/kpis', [DashboardController::class, 'kpis']);
            Route::get('dashboard/charts', [DashboardController::class, 'charts']);
        });

        Route::get('live/map', [DashboardController::class, 'liveMap'])
            ->middleware('permission:operations.live_map');

        // Live ridership, aggregated per vehicle. See OccupancyController for
        // why this is not a per-passenger map.
        Route::get('live/occupancy', OccupancyController::class)
            ->middleware('permission:operations.live_map');

        Route::prefix('reports')->middleware('permission:dashboard.view')->group(function (): void {
            Route::get('transport', [ReportController::class, 'transport']);
            Route::get('drivers', [ReportController::class, 'drivers']);
            Route::get('passengers', [ReportController::class, 'passengers']);
            Route::get('revenue', [ReportController::class, 'revenue'])
                ->middleware('permission:finance.manage');
        });

        Route::prefix('fleet')->middleware('permission:fleet.manage')->group(function (): void {
            Route::get('buses', [FleetController::class, 'index']);
            Route::post('buses', [FleetController::class, 'store']);
            Route::patch('buses/{bus}', [FleetController::class, 'update']);
            Route::get('buses/{bus}/qr', [FleetController::class, 'qrCode']);
            Route::post('buses/{bus}/qr/regenerate', [FleetController::class, 'regenerateQr']);
            Route::post('buses/{bus}/assignments', [FleetController::class, 'assignDriver']);
            Route::delete('assignments/{assignment}', [FleetController::class, 'revokeAssignment']);
        });

        Route::prefix('drivers')->middleware('permission:drivers.manage')->group(function (): void {
            Route::get('/', [DriverAdminController::class, 'index']);
            Route::post('/', [DriverAdminController::class, 'store']);
            Route::get('{driver}', [DriverAdminController::class, 'show']);
            Route::patch('{driver}', [DriverAdminController::class, 'update']);
            Route::post('{driver}/status', [DriverAdminController::class, 'changeStatus']);
            Route::post('{driver}/documents', [DriverAdminController::class, 'uploadDocument'])
                ->middleware('throttle:uploads');
        });

        Route::prefix('network')->middleware('permission:network.manage')->group(function (): void {
            Route::post('stops', [NetworkAdminController::class, 'storeStop']);
            Route::patch('stops/{stop}', [NetworkAdminController::class, 'updateStop']);
            Route::post('lines', [NetworkAdminController::class, 'storeLine']);
            Route::patch('lines/{line}', [NetworkAdminController::class, 'updateLine']);
            Route::post('lines/{line}/routes', [NetworkAdminController::class, 'storeRoute']);
            Route::post('routes/{route}/recalculate', [NetworkAdminController::class, 'recalculateRoute']);
        });

        Route::prefix('finance')->middleware('permission:finance.manage')->group(function (): void {
            Route::get('summary', [FinanceController::class, 'summary']);
            Route::get('transactions', [FinanceController::class, 'transactions']);
            Route::post('transactions/{walletTransaction}/reverse', [FinanceController::class, 'reverse']);
            Route::post('wallets/adjust', [FinanceController::class, 'adjust']);
            Route::get('wallets/audit', [FinanceController::class, 'auditWallet']);

            Route::get('fare-rules', [FinanceController::class, 'fareRules']);
            Route::post('fare-rules', [FinanceController::class, 'storeFareRule']);
            Route::patch('fare-rules/{fareRule}', [FinanceController::class, 'updateFareRule']);

            Route::get('settlements', [FinanceController::class, 'settlements']);
            Route::post('settlements', [FinanceController::class, 'createSettlement']);
            Route::post('settlements/{settlement}/approve', [FinanceController::class, 'approveSettlement']);
            Route::post('settlements/{settlement}/pay', [FinanceController::class, 'paySettlement']);
            Route::post('settlements/{settlement}/reject', [FinanceController::class, 'rejectSettlement']);
        });

        Route::prefix('merchants')->middleware('permission:merchants.manage')->group(function (): void {
            Route::get('/', [MerchantAdminController::class, 'index']);
            Route::post('/', [MerchantAdminController::class, 'store']);
            Route::get('{merchant}', [MerchantAdminController::class, 'show']);
            Route::post('{merchant}/status', [MerchantAdminController::class, 'changeStatus']);
            Route::post('{merchant}/terminals', [MerchantAdminController::class, 'addTerminal']);
            Route::post('{merchant}/staff', [MerchantAdminController::class, 'addStaff']);
        });

        Route::prefix('complaints')->middleware('permission:support.manage')->group(function (): void {
            Route::get('/', [SupportAdminController::class, 'index']);
            Route::get('{complaint}', [SupportAdminController::class, 'show']);
            Route::post('{complaint}/assign', [SupportAdminController::class, 'assign']);
            Route::post('{complaint}/reply', [SupportAdminController::class, 'reply']);
            Route::post('{complaint}/status', [SupportAdminController::class, 'changeStatus']);
            Route::get('{complaint}/attachments/{attachment}', [SupportAdminController::class, 'attachment']);
        });
    });
});
