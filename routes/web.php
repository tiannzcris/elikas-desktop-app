<?php

use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangayFinalCountController;
use App\Http\Controllers\CheckOutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataSecurityController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\EcBoardEntryController;
use App\Http\Controllers\EventLifecycleController;
use App\Http\Controllers\FamiliesToEvacuateController;
use App\Http\Controllers\EvacuationCenterController;
use App\Http\Controllers\FinalCountController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateHouseController;
use App\Http\Controllers\ReliefController;
use App\Http\Controllers\ReturningFamilyController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SelectedEventController;
use App\Http\Controllers\SentRecordController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UnlockController;
use App\Http\Controllers\WaitingRecordsController;
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

// Signed out after inactivity (RequireDesktopAccess lets these through).
Route::get('/signed-out', [UnlockController::class, 'show'])->name('unlock');
Route::post('/signed-out', [UnlockController::class, 'unlock'])->name('unlock.submit');
Route::post('/sign-out-idle', [UnlockController::class, 'lock'])->name('lock');

// Every route below needs a signed-in CSWD or barangay official account
// (RequireDesktopAccess, on the whole web group).
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::view('/about', 'about')->name('about');
Route::get('/data-security', [DataSecurityController::class, 'index'])->name('data-security.index');
Route::get('/password', [PasswordController::class, 'show'])->name('password.change');
Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
Route::get('/report-a-problem', [SupportController::class, 'index'])->name('support.index');
Route::post('/report-a-problem', [SupportController::class, 'store'])->name('support.store');
Route::post('/report-a-problem/refresh', [SupportController::class, 'refresh'])->name('support.refresh');
Route::get('/system-notices', [SupportController::class, 'notices'])->name('system-notices.feed');
Route::post('/data-security/timeout', [DataSecurityController::class, 'timeout'])->name('data-security.timeout');
Route::post('/data-security/prune-closed', [DataSecurityController::class, 'pruneClosed'])->name('data-security.prune');
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
    // Sync > Sent: correcting a registration already on the server (online only).
    Route::get('/sync/sent/entries/{entry}/edit', [SentRecordController::class, 'edit'])->name('sync.sent.edit');
    Route::patch('/sync/sent/entries/{entry}', [SentRecordController::class, 'update'])->name('sync.sent.update');

    Route::get('/ec-board', [EvacuationCenterController::class, 'ecBoardIndex'])->name('ec-board.index');
    Route::post('/events/families-arriving', [EventLifecycleController::class, 'familiesArriving'])->name('events.families-arriving');

    // Families to evacuate: the list per purok (online), check-in queued (offline too).
    Route::get('/families-to-evacuate', [FamiliesToEvacuateController::class, 'index'])->name('families-to-evacuate.index');
    Route::post('/families-to-evacuate', [FamiliesToEvacuateController::class, 'store'])->name('families-to-evacuate.store');
    Route::post('/families-to-evacuate/refresh', [FamiliesToEvacuateController::class, 'refresh'])->name('families-to-evacuate.refresh');
    Route::delete('/families-to-evacuate/{listedId}', [FamiliesToEvacuateController::class, 'destroy'])->where('listedId', '(local-)?[0-9]+')->name('families-to-evacuate.destroy');
    Route::post('/families-to-evacuate/{listedId}/check-in', [FamiliesToEvacuateController::class, 'checkIn'])->where('listedId', '(local-)?[0-9]+')->name('families-to-evacuate.check-in');
    // The barangay's final count: read offline, submitted online.
    Route::get('/final-count', [BarangayFinalCountController::class, 'index'])->name('final-count.index');
    Route::post('/final-count/refresh', [BarangayFinalCountController::class, 'refresh'])->name('final-count.refresh');
    Route::post('/final-count/submit', [BarangayFinalCountController::class, 'submit'])->name('final-count.submit');
    // Waiting records to another computer (an encrypted file, e.g. on a USB drive).
    Route::post('/waiting-records/export', [WaitingRecordsController::class, 'export'])->name('waiting-records.export');
    Route::post('/waiting-records/import', [WaitingRecordsController::class, 'import'])->name('waiting-records.import');

    // Relief boxes: the families of each round, Give box queued with client_ref.
    Route::get('/relief', [ReliefController::class, 'index'])->name('relief.index');
    Route::post('/relief/refresh', [ReliefController::class, 'refresh'])->name('relief.refresh');
    Route::post('/relief/give', [ReliefController::class, 'give'])->name('relief.give');

    // Print and download: files made on this computer, officials only.
    Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');
    Route::post('/downloads', [DownloadController::class, 'save'])->name('downloads.save');
    Route::post('/downloads/official-ec-board', [DownloadController::class, 'official'])->name('downloads.official');
    Route::post('/puroks/{purok}/marks', [FamiliesToEvacuateController::class, 'markPurok'])->name('puroks.mark');

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
