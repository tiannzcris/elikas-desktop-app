<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EcBoardEntryController;
use App\Http\Controllers\EvacuationCenterController;
use App\Http\Controllers\EvacueeController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\SystemUpdateController;
use Illuminate\Support\Facades\Route;

// Reachable even before login works, and exempted from
// EnsureDatabaseIsUpToDate itself (see bootstrap/app.php) -- a schema
// mismatch can affect tables login depends on, so this can't sit behind
// auth or behind the very check it exists to handle.
Route::get('/system/update-required', [SystemUpdateController::class, 'show'])->name('system.update-required');
Route::post('/system/update-required', [SystemUpdateController::class, 'run'])->name('system.update-required.run');

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/reference-data/refresh', [AuthController::class, 'refreshReferenceDataAction'])->name('reference-data.refresh');

Route::get('/families', [FamilyController::class, 'index'])->name('families.index');
Route::get('/families/create', [FamilyController::class, 'create'])->name('families.create');
Route::post('/families', [FamilyController::class, 'store'])->name('families.store');
Route::post('/families/sync', [FamilyController::class, 'sync'])->name('families.sync');
Route::get('/families/{family}/edit', [FamilyController::class, 'edit'])->name('families.edit');
Route::put('/families/{family}', [FamilyController::class, 'update'])->name('families.update');
Route::delete('/families/{family}', [FamilyController::class, 'destroy'])->name('families.destroy');

Route::get('/evacuees', [EvacueeController::class, 'index'])->name('evacuees.index');

// Standalone fast-entry path to EC Board: barangay -> center -> that
// center's board. Kept under its own /ec-board/... prefix (not nested
// under /evacuation-centers/...) so the URL itself signals this is a
// separate section, not a sub-page of the Evacuation Centers management
// area below (mirrors the same separation on the web dashboard). This is
// what the sidebar's "EC Board" link points to now.
Route::get('/ec-board', [EvacuationCenterController::class, 'ecBoardBarangays'])->name('ec-board.index');
Route::get('/ec-board/{barangay}', [EvacuationCenterController::class, 'ecBoardCenters'])->name('ec-board.centers');

Route::get('/evacuation-centers', [EvacuationCenterController::class, 'index'])->name('evacuation-centers.index');
Route::get('/evacuation-centers/{center}', [EvacuationCenterController::class, 'show'])->name('evacuation-centers.show');
Route::get('/evacuation-centers/{center}/ec-board', [EvacuationCenterController::class, 'ecBoard'])->name('evacuation-centers.ec-board');
Route::post('/evacuation-centers/{center}/sectoral', [EvacuationCenterController::class, 'saveSectoral'])->name('evacuation-centers.sectoral.update');
// Called client-side via fetch() AFTER the EC Board page itself has
// rendered -- never part of that page's own synchronous render, since a
// blocking live call there starves this single-request-at-a-time local
// server's concurrent CSS/JS asset requests while offline (see
// EvacuationCenterController::ecBoard()'s docblock).
Route::get('/evacuation-centers/{center}/breakdown-refresh', [EvacuationCenterController::class, 'refreshBreakdown'])->name('evacuation-centers.breakdown-refresh');
Route::get('/evacuation-centers/{center}/households-refresh', [EvacuationCenterController::class, 'refreshHouseholds'])->name('evacuation-centers.households-refresh');
// "Quick Departure" -- called client-side via fetch(), online-only, no
// offline/local path at all. See EvacuationCenterController::
// quickDeparture()'s own docblock for why this differs from every other
// write on this page.
Route::post('/evacuation-centers/{center}/quick-departure', [EvacuationCenterController::class, 'quickDeparture'])->name('evacuation-centers.quick-departure');
Route::post('/evacuation-centers/{center}/evacuees', [EcBoardEntryController::class, 'store'])->name('ec-board-entries.store');
Route::get('/ec-board-entries/{entry}/edit', [EcBoardEntryController::class, 'edit'])->name('ec-board-entries.edit');
Route::put('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'update'])->name('ec-board-entries.update');
Route::delete('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'destroy'])->name('ec-board-entries.destroy');
