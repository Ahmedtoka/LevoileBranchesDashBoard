<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoUserController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ObservationController;
use App\Http\Controllers\Api\ObservationVisitController;
use App\Http\Controllers\Api\OpsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

// ---- Auth ----
Route::post('/login', [AuthController::class, 'login']);
Route::get('/demo-users', [DemoUserController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile/password', [ProfileController::class, 'changePassword']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);

    // ---- Visits ----
    Route::get('/checklist/today', [VisitController::class, 'today']);
    Route::get('/visits', [VisitController::class, 'index']);
    Route::get('/visits/branch-checklists', [VisitController::class, 'branchChecklists']);
    Route::get('/visits/{visit}', [VisitController::class, 'show']);
    Route::post('/visits/{visit}/checkin', [VisitController::class, 'checkin']);
    Route::post('/visits/{visit}/start', [VisitController::class, 'start']);
    Route::post('/visits/{visit}/answers', [VisitController::class, 'submitAnswer']);
    Route::post('/visits/{visit}/evidence', [VisitController::class, 'uploadEvidence']);
    Route::post('/visits/{visit}/submit', [VisitController::class, 'submit']);

    // ---- Tickets ----
    Route::get('/tickets/mine', [TicketController::class, 'mine']);
    Route::get('/tickets/raised', [TicketController::class, 'raised']);
    Route::get('/my/requests', [TicketController::class, 'myRequests']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus']);
    Route::post('/tickets/{ticket}/evidence', [TicketController::class, 'uploadEvidence']);
    Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply']);
    Route::post('/tickets/{ticket}/decline', [TicketController::class, 'decline']);
    Route::post('/tickets/{ticket}/send-for-approval', [TicketController::class, 'sendForApproval']);
    Route::post('/tickets/{ticket}/approve', [TicketController::class, 'approve']);
    Route::post('/tickets/{ticket}/reject', [TicketController::class, 'reject']);
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign']);
    Route::post('/tickets/{ticket}/priority', [TicketController::class, 'updatePriority']);

    // ---- Departments ----
    Route::get('/departments/tickets', [TicketController::class, 'department']);
    Route::get('/departments/overview', [TicketController::class, 'departmentOverview']);
    Route::get('/departments/employees', [TicketController::class, 'departmentEmployees']);
    Route::get('/departments/branches', [TicketController::class, 'departmentBranches']);
    Route::get('/departments/branch-tickets', [TicketController::class, 'branchTickets']);
    Route::get('/departments/technicians', [TicketController::class, 'technicians']);
    Route::get('/departments/technician-tickets', [TicketController::class, 'technicianTickets']);
    Route::post('/departments/assign-bulk', [TicketController::class, 'assignBulk']);
    Route::post('/departments/ticket/{ticket}/status', [TicketController::class, 'deptSetStatus']);

    // ---- Maintenance requests ----
    Route::get('/maintenance/items', [MaintenanceController::class, 'items']);
    Route::get('/maintenance/branches', [MaintenanceController::class, 'branches']);
    Route::post('/maintenance/upload', [MaintenanceController::class, 'upload']);
    Route::post('/maintenance/requests', [MaintenanceController::class, 'store']);
    Route::get('/branch/overview', [MaintenanceController::class, 'branchOverview']);

    // ---- Operations manager ----
    Route::get('/ops/overview', [OpsController::class, 'overview']);
    Route::get('/ops/teams', [OpsController::class, 'teams']);
    Route::get('/ops/pickers', [OpsController::class, 'pickers']);
    Route::post('/ops/schedule', [OpsController::class, 'schedule']);
    Route::get('/ops/visits', [OpsController::class, 'visits']);
    Route::get('/ops/tickets', [OpsController::class, 'tickets']);
    Route::get('/ops/maintenance', [OpsController::class, 'maintenance']);

    // ---- Observations & risk (spec section 11; the web page calls the same endpoints with the session) ----
    // meta is registered before {observation}, and {observation} only matches numbers.
    Route::get('/observations/meta', [ObservationController::class, 'meta']);
    Route::get('/observation-visits', [ObservationVisitController::class, 'index']);
    Route::post('/observation-visits', [ObservationVisitController::class, 'store']);
    Route::get('/observation-visits/{visit}', [ObservationVisitController::class, 'show'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/photos', [ObservationVisitController::class, 'uploadPhoto'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/ai-check', [ObservationVisitController::class, 'aiCheck'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/observations', [ObservationVisitController::class, 'upsert'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/submit', [ObservationVisitController::class, 'submit'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/classify', [ObservationVisitController::class, 'classify'])->whereNumber('visit');
    Route::post('/observation-visits/{visit}/approve', [ObservationVisitController::class, 'approve'])->whereNumber('visit');
    Route::get('/observations', [ObservationController::class, 'index']);
    Route::get('/observations/{observation}', [ObservationController::class, 'show'])->whereNumber('observation');
    Route::put('/observations/{observation}', [ObservationController::class, 'update'])->whereNumber('observation');
    Route::delete('/observations/{observation}', [ObservationController::class, 'destroyDraft'])->whereNumber('observation');
    Route::post('/observations/{observation}/verify', [ObservationController::class, 'verify'])->whereNumber('observation');
    Route::get('/branches/{branch}/observations/pending-verification', [ObservationController::class, 'pendingVerification'])->whereNumber('branch');

    // ---- Notifications ----
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});
