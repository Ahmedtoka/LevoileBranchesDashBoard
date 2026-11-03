<?php

use App\Http\Controllers\Web\FinanceController;
use Illuminate\Support\Facades\Route;

// Finance department menu (2026-11-03). Required from routes/web.php inside the auth group.
Route::prefix('finance')->name('finance.')->group(function () {
    Route::get('/', [FinanceController::class, 'summary'])->name('summary');
    Route::get('/problems', [FinanceController::class, 'index'])->name('index');
    Route::get('/problems/export.csv', [FinanceController::class, 'export'])->name('export');
    Route::get('/reviews/new', [FinanceController::class, 'create'])->name('create');
    Route::post('/reviews', [FinanceController::class, 'store'])->name('store');
});
