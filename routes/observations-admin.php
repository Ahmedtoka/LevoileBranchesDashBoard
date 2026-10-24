<?php

use App\Http\Controllers\Web\Observations\AreaController;
use App\Http\Controllers\Web\Observations\RiskLevelController;
use Illuminate\Support\Facades\Route;

// Observations module: admin screens (Plan C). Required from routes/web.php inside the `auth` group.

// ---- Areas ----
Route::get('/admin/areas', [AreaController::class, 'index'])->name('obs.areas.index');
Route::post('/admin/areas', [AreaController::class, 'store'])->name('obs.areas.store');
Route::put('/admin/areas/{area}', [AreaController::class, 'update'])->name('obs.areas.update');
Route::delete('/admin/areas/{area}', [AreaController::class, 'destroy'])->name('obs.areas.destroy');
Route::post('/admin/areas/{area}/branches', [AreaController::class, 'assignBranches'])->name('obs.areas.branches');

// ---- Risk levels (edit only; the 5 levels are fixed) ----
Route::get('/admin/risk-levels', [RiskLevelController::class, 'index'])->name('obs.risk.index');
Route::put('/admin/risk-levels/{riskLevel}', [RiskLevelController::class, 'update'])->name('obs.risk.update');
