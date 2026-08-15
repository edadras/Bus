<?php

use App\Domain\Fleet\Models\Driver;
use App\Domain\Support\Models\Complaint;
use App\Http\Controllers\Web\ClientAppController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\PaymentReturnController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The browser surfaces: a public landing page, three installable client apps
| and the admin panel shell. All of them talk to /api/v1 for data, so there is
| exactly one API for web and native clients alike.
|
*/

Route::get('/', LandingController::class)->name('home');

/*
| Progressive Web Apps
*/
Route::get('/app/passenger', [ClientAppController::class, 'passenger'])->name('passenger.app');
Route::get('/app/driver', [ClientAppController::class, 'driver'])->name('driver.app');
Route::get('/app/merchant', [ClientAppController::class, 'merchant'])->name('merchant.app');

// Deep link from a scanned sticker: /q/<token> opens the passenger app.
Route::get('/q/{token}', [ClientAppController::class, 'scanEntry'])
    ->where('token', '.*')
    ->name('scan.entry');

Route::get('/manifest/{app}.webmanifest', [ClientAppController::class, 'manifest'])
    ->whereIn('app', ['passenger', 'driver', 'merchant'])
    ->name('pwa.manifest');

Route::get('/sw.js', [ClientAppController::class, 'serviceWorker'])->name('pwa.sw');
Route::get('/offline', [ClientAppController::class, 'offline'])->name('pwa.offline');

/*
| Signed, short-lived downloads for files held on the private disk. Never a
| public path: complaint photos routinely contain faces, plates and interiors,
| and a driver's documents are their licence and national card.
|
| These must be registered BEFORE the admin shell below, which claims
| `/admin/{any}` so that a bookmarked panel URL reloads. First match wins, so a
| download registered after it would silently serve the panel's HTML instead of
| the file — with the `signed` middleware never running at all.
*/
Route::get('/admin/complaints/{complaint}/attachments/{attachment}/download', function (
    Complaint $complaint,
    int $attachment,
) {
    $file = $complaint->attachments()->findOrFail($attachment);

    return Storage::disk('local')->download($file->file_path, $file->original_name);
})->middleware('signed')->name('admin.complaints.attachment.download');

Route::get('/admin/drivers/{driver}/documents/{document}/download', function (
    Driver $driver,
    int $document,
) {
    $file = $driver->documents()->findOrFail($document);

    return Storage::disk('local')->download($file->file_path, $file->original_name);
})->middleware('signed')->name('admin.drivers.document.download');

/*
| Admin panel shell. Authentication happens against the API from the browser;
| these routes only serve the application shell.
*/
Route::prefix('admin')->group(function (): void {
    Route::view('/login', 'admin.login')->name('admin.login');
    Route::view('/{any?}', 'admin.shell')->where('any', '.*')->name('admin.shell');
});

/*
| Payment gateway redirect targets.
*/
Route::get('/payments/{payment}/sandbox', [PaymentReturnController::class, 'sandbox'])->name('payments.sandbox');
Route::get('/payments/{payment}/callback', [PaymentReturnController::class, 'callback'])->name('payments.callback');
Route::get('/payments/{payment}/pending', [PaymentReturnController::class, 'pending'])->name('payments.pending');
