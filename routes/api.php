<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

// ---- Auth ----
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ---- Visits ----
    Route::get('/visits', [VisitController::class, 'index']);
    Route::get('/visits/{visit}', [VisitController::class, 'show']);
    Route::post('/visits/{visit}/checkin', [VisitController::class, 'checkin']);
    Route::post('/visits/{visit}/start', [VisitController::class, 'start']);
    Route::post('/visits/{visit}/answers', [VisitController::class, 'submitAnswer']);
    Route::post('/visits/{visit}/evidence', [VisitController::class, 'uploadEvidence']);
    Route::post('/visits/{visit}/submit', [VisitController::class, 'submit']);

    // ---- Tickets ----
    Route::get('/tickets/mine', [TicketController::class, 'mine']);
    Route::get('/tickets/raised', [TicketController::class, 'raised']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus']);
    Route::post('/tickets/{ticket}/evidence', [TicketController::class, 'uploadEvidence']);
    Route::post('/tickets/{ticket}/send-for-approval', [TicketController::class, 'sendForApproval']);
    Route::post('/tickets/{ticket}/approve', [TicketController::class, 'approve']);
    Route::post('/tickets/{ticket}/reject', [TicketController::class, 'reject']);
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign']);

    // ---- Departments ----
    Route::get('/departments/tickets', [TicketController::class, 'department']);
    Route::get('/departments/overview', [TicketController::class, 'departmentOverview']);
    Route::get('/departments/employees', [TicketController::class, 'departmentEmployees']);

    // ---- Maintenance requests ----
    Route::get('/maintenance/items', [MaintenanceController::class, 'items']);
    Route::get('/maintenance/branches', [MaintenanceController::class, 'branches']);
    Route::post('/maintenance/upload', [MaintenanceController::class, 'upload']);
    Route::post('/maintenance/requests', [MaintenanceController::class, 'store']);
    Route::get('/branch/overview', [MaintenanceController::class, 'branchOverview']);

    // ---- Notifications ----
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});
