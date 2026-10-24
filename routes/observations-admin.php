<?php

use App\Http\Controllers\Web\Observations\AreaController;
use App\Http\Controllers\Web\Observations\CatalogItemController;
use App\Http\Controllers\Web\Observations\CategoryController;
use App\Http\Controllers\Web\Observations\ReviewController;
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

// ---- Observation categories ----
Route::get('/admin/observation-categories', [CategoryController::class, 'index'])->name('obs.categories.index');
Route::post('/admin/observation-categories', [CategoryController::class, 'store'])->name('obs.categories.store');
Route::post('/admin/observation-categories/reorder', [CategoryController::class, 'reorder'])->name('obs.categories.reorder');
Route::put('/admin/observation-categories/{category}', [CategoryController::class, 'update'])->name('obs.categories.update');
Route::delete('/admin/observation-categories/{category}', [CategoryController::class, 'destroy'])->name('obs.categories.destroy');

// ---- Observation catalog items ----
Route::get('/admin/observation-catalog', [CatalogItemController::class, 'index'])->name('obs.catalog.index');
Route::post('/admin/observation-catalog', [CatalogItemController::class, 'store'])->name('obs.catalog.store');
Route::put('/admin/observation-catalog/{item}', [CatalogItemController::class, 'update'])->name('obs.catalog.update');
Route::post('/admin/observation-catalog/{item}/toggle', [CatalogItemController::class, 'toggle'])->name('obs.catalog.toggle');
Route::delete('/admin/observation-catalog/{item}', [CatalogItemController::class, 'destroy'])->name('obs.catalog.destroy');

// ---- Classification review queue (spec 9.6) ----
Route::get('/observations/review', [ReviewController::class, 'index'])->name('obs.review.index');
Route::post('/observations/review/bulk-merge', [ReviewController::class, 'bulkMerge'])->name('obs.review.bulk');
Route::put('/observations/review/{observation}', [ReviewController::class, 'update'])->name('obs.review.update');
Route::post('/observations/review/{observation}/approve', [ReviewController::class, 'approve'])->name('obs.review.approve');
Route::post('/observations/review/{observation}/merge', [ReviewController::class, 'merge'])->name('obs.review.merge');
Route::post('/observations/review/{observation}/promote', [ReviewController::class, 'promote'])->name('obs.review.promote');
