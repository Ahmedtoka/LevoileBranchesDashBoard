<?php

use App\Http\Controllers\Web\Observations\ApprovalController;
use App\Http\Controllers\Web\TicketController;
use Illuminate\Support\Facades\Route;

// Observations module: manager approval gate (Plan H). Required from routes/web.php inside the `auth` group.

// ---- Visits awaiting the reporting department's manager ----
Route::get('/observations/visits/to-approve', [ApprovalController::class, 'index'])->name('obs.approval.index');
Route::get('/observations/visits/to-approve/{visit}', [ApprovalController::class, 'show'])->name('obs.approval.show');
Route::post('/observations/visits/to-approve/{visit}/classify', [ApprovalController::class, 'classify'])->name('obs.approval.classify');
Route::put('/observations/visits/to-approve/{visit}/observations/{observation}', [ApprovalController::class, 'updateObservation'])->name('obs.approval.observation');
Route::post('/observations/visits/to-approve/{visit}/approve', [ApprovalController::class, 'approve'])->name('obs.approval.approve');

// ---- Responsible department's reply on a ticket (working / solved + note) ----
Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
