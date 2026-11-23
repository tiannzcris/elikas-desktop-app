<?php

use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckOutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EcBoardEntryController;
use App\Http\Controllers\EventLifecycleController;
use App\Http\Controllers\EvacuationCenterController;
use App\Http\Controllers\FinalCountController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateHouseController;
use App\Http\Controllers\ReturningFamilyController;
use App\Http\Controllers\SelectedEventController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\TransferController;
use App\Http\Middleware\CityOnly;
use App\Http\Middleware\OfficialOnly;
use Illuminate\Support\Facades\Route;

// Reachable before login and exempt from EnsureDatabaseIsUpToDate itself
// (see bootstrap/app.php): a schema mismatch can affect login's tables.
Route::get('/system/update-required', [SystemUpdateController::class, 'show'])->name('system.update-required');
Route::post('/system/update-required', [SystemUpdateController::class, 'run'])->name('system.update-required.run');

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/use-the-web', [AuthController::class, 'useWeb'])->name('use-web');

// Every route below needs a signed-in CSWD or barangay official account
// (RequireDesktopAccess, on the whole web group).
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::view('/about', 'about')->name('about');
Route::get('/app-version', [AppVersionController::class, 'check'])->name('app-version.check');
Route::get('/app-version/download', [AppVersionController::class, 'download'])->name('app-version.download');
Route::post('/selected-event', [SelectedEventController::class, 'update'])->name('selected-event.update');

// The bell, and transfers between centers (officials and CSWD; writes online only).
Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
Route::post('/notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
Route::get('/notifications/open', [NotificationController::class, 'open'])->name('notifications.open');
Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
Route::post('/transfers/refresh', [TransferController::class, 'refresh'])->name('transfers.refresh');
Route::post('/transfers/{requestId}/decide', [TransferController::class, 'decide'])->whereNumber('requestId')->name('transfers.decide');
Route::post('/reference-data/refresh', [AuthController::class, 'refreshReferenceDataAction'])->name('reference-data.refresh');

// The barangay official's work: registration at their own centers, and sync.
Route::middleware(OfficialOnly::class)->group(function () {
    Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('/sync/step', [SyncController::class, 'step'])->name('sync.step');
    Route::post('/sync/retry', [SyncController::class, 'retry'])->name('sync.retry');
    Route::get('/sync/probe', [SyncController::class, 'probe'])->name('sync.probe');

    Route::post('/sync/all', [SyncController::class, 'all'])->name('sync.all');
    Route::post('/sync/returning/{returning}/decide', [SyncController::class, 'decide'])->name('sync.decide');
    Route::delete('/sync/returning/{returning}', [SyncController::class, 'forgetReturning'])->name('sync.forget-returning');

    Route::get('/ec-board', [EvacuationCenterController::class, 'ecBoardIndex'])->name('ec-board.index');
    Route::post('/events/families-arriving', [EventLifecycleController::class, 'familiesArriving'])->name('events.families-arriving');

    Route::get('/evacuation-centers/{center}/ec-board', [EvacuationCenterController::class, 'ecBoard'])->name('evacuation-centers.ec-board');
    // Fetched by the page after it renders, never during: the local server
    // answers one request at a time, so a slow live call would hold up the
    // page's own CSS/JS while offline.
    Route::get('/evacuation-centers/{center}/board-refresh', [EvacuationCenterController::class, 'refreshBoard'])->name('evacuation-centers.board-refresh');
    Route::get('/evacuation-centers/{center}/households-refresh', [EvacuationCenterController::class, 'refreshHouseholds'])->name('evacuation-centers.households-refresh');
    Route::post('/evacuation-centers/{center}/evacuees', [EcBoardEntryController::class, 'store'])->name('ec-board-entries.store');
    Route::get('/ec-board-entries/{entry}/edit', [EcBoardEntryController::class, 'edit'])->name('ec-board-entries.edit');
    Route::put('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'update'])->name('ec-board-entries.update');
    Route::delete('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'destroy'])->name('ec-board-entries.destroy');

    Route::post('/evacuation-centers/{center}/check-outs', [CheckOutController::class, 'store'])->name('check-outs.store');
    Route::get('/evacuation-centers/{center}/move', [TransferController::class, 'moveForm'])->name('transfers.move');
    Route::post('/evacuation-centers/{center}/move/direct', [TransferController::class, 'moveDirect'])->name('transfers.move-direct');
    Route::post('/evacuation-centers/{center}/move/request', [TransferController::class, 'requestTransfer'])->name('transfers.request');
    Route::get('/private-houses', [PrivateHouseController::class, 'index'])->name('private-houses.index');
    Route::get('/private-houses/add', [PrivateHouseController::class, 'create'])->name('private-houses.create');
    Route::post('/private-houses', [PrivateHouseController::class, 'store'])->name('private-houses.store');
    Route::get('/evacuation-centers/{center}/returning-families', [ReturningFamilyController::class, 'index'])->name('returning-families.index');
    Route::post('/evacuation-centers/{center}/returning-families', [ReturningFamilyController::class, 'store'])->name('returning-families.store');
});

// CSWD's read-only pages: kept on this device, "As of" the last fetch;
// validation and every other action are on the web.
Route::middleware(CityOnly::class)->group(function () {
    Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    Route::post('/monitoring/refresh', [MonitoringController::class, 'refresh'])->name('monitoring.refresh');
    Route::get('/final-counts', [FinalCountController::class, 'index'])->name('final-counts.index');
    Route::post('/final-counts/refresh', [FinalCountController::class, 'refresh'])->name('final-counts.refresh');
    Route::get('/transfers/move', [TransferController::class, 'cityMoveForm'])->name('transfers.city-move');
    Route::post('/transfers/move', [TransferController::class, 'cityMove'])->name('transfers.city-move.store');
    Route::post('/final-counts/submit-for-barangay', [FinalCountController::class, 'submitOnBehalf'])->name('final-counts.submit-on-behalf');
});
