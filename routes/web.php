<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EcBoardEntryController;
use App\Http\Controllers\EvacuationCenterController;
use App\Http\Controllers\EvacueeController;
use App\Http\Controllers\FamilyController;
use Illuminate\Support\Facades\Route;

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

Route::get('/evacuation-centers', [EvacuationCenterController::class, 'index'])->name('evacuation-centers.index');
Route::get('/evacuation-centers/{center}', [EvacuationCenterController::class, 'show'])->name('evacuation-centers.show');
Route::post('/evacuation-centers/{center}/evacuees', [EcBoardEntryController::class, 'store'])->name('ec-board-entries.store');
Route::get('/ec-board-entries/{entry}/edit', [EcBoardEntryController::class, 'edit'])->name('ec-board-entries.edit');
Route::put('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'update'])->name('ec-board-entries.update');
Route::delete('/ec-board-entries/{entry}', [EcBoardEntryController::class, 'destroy'])->name('ec-board-entries.destroy');
