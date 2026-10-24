<?php

use App\Http\Controllers\Web\BranchController;
use App\Http\Controllers\Web\CoverageController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DepartmentController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\MaintenanceDashboardController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\TemplateController;
use App\Http\Controllers\Web\TemplateTypeController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Language toggle (Arabic / English)
Route::get('/locale/{locale}', function (string $locale) {
    session(['locale' => in_array($locale, ['ar', 'en'], true) ? $locale : 'ar']);
    return back();
})->name('locale.switch');

// Auth
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/quick', [LoginController::class, 'quick'])->name('login.quick');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ---- Demo data (generate / wipe everything across all roles) ----
    Route::post('/demo/generate', [\App\Http\Controllers\Web\DemoDataController::class, 'generate'])->name('demo.generate');
    Route::post('/demo/wipe', [\App\Http\Controllers\Web\DemoDataController::class, 'wipe'])->name('demo.wipe');

    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('/branches/{branch}', [BranchController::class, 'show'])->name('branches.show');

    Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');
    Route::get('/visits/schedule', [VisitController::class, 'create'])->name('visits.schedule');
    Route::post('/visits/schedule', [VisitController::class, 'store'])->name('visits.store');
    Route::get('/visits/{visit}', [VisitController::class, 'show'])->name('visits.show');

    // ---- Users management ----
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::get('/roles', [\App\Http\Controllers\Web\RoleController::class, 'index'])->name('roles.index');
    Route::get('/strings', [\App\Http\Controllers\Web\TranslationController::class, 'index'])->name('translations.index');
    Route::put('/strings', [\App\Http\Controllers\Web\TranslationController::class, 'update'])->name('translations.update');

    // ---- Template types ----
    Route::get('/types', [TemplateTypeController::class, 'index'])->name('types.index');
    Route::post('/types', [TemplateTypeController::class, 'store'])->name('types.store');
    Route::delete('/types/{type}', [TemplateTypeController::class, 'destroy'])->name('types.destroy');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::post('/tickets/{ticket}/transition', [TicketController::class, 'transition'])->name('tickets.transition');

    // ---- Branch coverage ----
    Route::get('/coverage', [CoverageController::class, 'index'])->name('coverage.index');
    Route::post('/coverage/{user}', [CoverageController::class, 'update'])->name('coverage.update');

    // ---- Maintenance command center ----
    Route::get('/maintenance', [MaintenanceDashboardController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance/wipe', [MaintenanceDashboardController::class, 'wipe'])->name('maintenance.wipe');
    Route::post('/maintenance/generate', [MaintenanceDashboardController::class, 'generate'])->name('maintenance.generate');

    // ---- Departments ----
    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::get('/departments/{department}/board', [DepartmentController::class, 'board'])->name('departments.board');

    // ---- Notifications ----
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::get('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    // ---- Purchasing module ----
    Route::get('/purchasing', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'index'])->name('purchasing.index');
    Route::post('/purchasing', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'store'])->name('purchasing.store');
    Route::get('/purchasing/kpis', [\App\Http\Controllers\Web\PurchaseKpiController::class, 'index'])->name('purchasing.kpis');
    Route::post('/purchasing/bulk', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'bulk'])->name('purchasing.bulk');
    Route::get('/purchasing/vendors', [\App\Http\Controllers\Web\VendorController::class, 'index'])->name('vendors.index');
    Route::post('/purchasing/vendors', [\App\Http\Controllers\Web\VendorController::class, 'store'])->name('vendors.store');
    Route::put('/purchasing/vendors/{vendor}', [\App\Http\Controllers\Web\VendorController::class, 'update'])->name('vendors.update');
    Route::delete('/purchasing/vendors/{vendor}', [\App\Http\Controllers\Web\VendorController::class, 'destroy'])->name('vendors.destroy');
    Route::get('/purchasing/{pr}', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'show'])->name('purchasing.show');
    Route::post('/purchasing/{pr}/assign-buyer', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'assignBuyer'])->name('purchasing.assignBuyer');
    Route::post('/purchasing/{pr}/quotation', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'addQuotation'])->name('purchasing.quotation');
    Route::post('/purchasing/{pr}/select-quotation', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'selectQuotation'])->name('purchasing.selectQuotation');
    Route::post('/purchasing/{pr}/finance', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'finance'])->name('purchasing.finance');
    Route::post('/purchasing/{pr}/po', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'issuePo'])->name('purchasing.po');
    Route::post('/purchasing/{pr}/execute', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'execute'])->name('purchasing.execute');
    Route::post('/purchasing/{pr}/receive', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'receive'])->name('purchasing.receive');
    Route::post('/purchasing/{pr}/status', [\App\Http\Controllers\Web\PurchaseRequestController::class, 'status'])->name('purchasing.status');

    // ---- Observations & risk (operations web) ----
    // export.csv MUST stay above any future /observations/{observation} route, or "export.csv" is read as an id.
    Route::get('/observations', [\App\Http\Controllers\Web\Observations\ObservationController::class, 'index'])->name('observations.index');
    Route::get('/observations/export.csv', [\App\Http\Controllers\Web\Observations\ObservationController::class, 'export'])->name('observations.export');

    // ---- KPIs & deep reports ----
    Route::get('/kpis', [\App\Http\Controllers\Web\KpiController::class, 'index'])->name('kpis.index');
    Route::get('/kpis/tickets', [\App\Http\Controllers\Web\KpiController::class, 'tickets'])->name('kpis.tickets');
    Route::get('/kpis/visits', [\App\Http\Controllers\Web\KpiController::class, 'visits'])->name('kpis.visits');
    Route::get('/kpis/integrity', [\App\Http\Controllers\Web\KpiController::class, 'integrity'])->name('kpis.integrity');
    Route::get('/kpis/compliance', [\App\Http\Controllers\Web\KpiController::class, 'compliance'])->name('kpis.compliance');

    // ---- Checklist Builder ----
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    Route::post('/templates/{template}/sections', [TemplateController::class, 'storeSection'])->name('sections.store');
    Route::put('/sections/{section}', [TemplateController::class, 'updateSection'])->name('sections.update');
    Route::delete('/sections/{section}', [TemplateController::class, 'destroySection'])->name('sections.destroy');

    Route::post('/sections/{section}/questions', [TemplateController::class, 'storeQuestion'])->name('questions.store');
    Route::put('/questions/{question}', [TemplateController::class, 'updateQuestion'])->name('questions.update');
    Route::delete('/questions/{question}', [TemplateController::class, 'destroyQuestion'])->name('questions.destroy');
});
