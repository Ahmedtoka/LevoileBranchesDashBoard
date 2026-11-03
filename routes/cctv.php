<?php

use App\Http\Controllers\Web\CctvController;
use Illuminate\Support\Facades\Route;

// CCTV department menu (2026-11-03). Required from routes/web.php inside the auth group.
Route::prefix('cctv')->name('cctv.')->group(function () {
    Route::get('/', [CctvController::class, 'summary'])->name('summary');
    Route::get('/violations', [CctvController::class, 'index'])->name('index');
    Route::get('/violations/export.csv', [CctvController::class, 'export'])->name('export');
    Route::get('/violations/new', [CctvController::class, 'create'])->name('create');
    Route::post('/violations', [CctvController::class, 'store'])->name('store');
    Route::post('/clean', [CctvController::class, 'clean'])->name('clean');
});
