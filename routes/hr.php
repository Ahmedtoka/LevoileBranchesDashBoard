<?php

use App\Http\Controllers\Web\HrController;
use Illuminate\Support\Facades\Route;

// HR menu (2026-11-03): staff directory, employee pages, uploading the HR export. Required from routes/web.php inside the auth group.
Route::prefix('hr')->name('hr.')->group(function () {
    Route::get('/', [HrController::class, 'summary'])->name('summary');
    Route::get('/employees/export.csv', [HrController::class, 'export'])->name('export');
    Route::get('/employees/{employee}', [HrController::class, 'show'])->name('show');
    Route::get('/import', [HrController::class, 'importForm'])->name('import');
    Route::post('/import', [HrController::class, 'preview'])->name('preview');
    Route::post('/import/apply', [HrController::class, 'apply'])->name('apply');
});
