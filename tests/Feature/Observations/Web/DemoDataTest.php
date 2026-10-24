<?php

namespace Tests\Feature\Observations\Web;

use App\Models\AiClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\Ai\NullClassifier;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Seeds;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // The observations demo runs only where the demo logins are on.
        config(['app.demo_login' => true]);
        // Never call the real classifier from a test.
        $this->app->instance(ClassifierContract::class, new NullClassifier);

        $this->admin = Seeds::user('super_admin');
    }

    private function generate(): void
    {
        $this->actingAs($this->admin)
            ->from('/dashboard')
            ->post(route('demo.generate'))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status');
    }

    public function test_generate_creates_demo_observations_in_every_status(): void
    {
        $this->generate();

        $statuses = Observation::pluck('status')->unique()->all();
        foreach (['open', 'in_progress', 'waiting_verification', 'verified_closed', 'reopened', 'positive', 'closed_no_action'] as $status) {
            $this->assertContains($status, $statuses, "No demo observation in status [{$status}].");
        }
        $this->assertTrue(Observation::where('classification_status', 'ai_pending_review')->exists());

        // Everything is marked demo, every visit is submitted and spread over the last 10 days.
        $this->assertSame(0, Observation::where(fn ($q) => $q->whereNull('import_source')->orWhere('import_source', '!=', 'demo'))->count());
        $this->assertSame(7, ObservationVisit::count());
        // Plan H: every historical visit is approved (tickets exist), ONE recent CCTV visit waits for its manager.
        $this->assertSame(6, ObservationVisit::where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')->count());
        $pending = ObservationVisit::where('status', 'submitted')->get();
        $this->assertCount(1, $pending);
        $pending = $pending->first();
        $cctvManager = User::where('email', 'cctv.manager@levoile.test')->firstOrFail();
        $this->assertSame(User::where('email', 'cctv.emp@levoile.test')->value('id'), (int) $pending->user_id);
        $this->assertSame(3, $pending->observations()->whereNull('catalog_item_id')->whereNull('responsible_department_id')->whereNull('ticket_id')->count());
        $this->assertNull($pending->approved_by);
        $this->assertSame(1, Notification::where('user_id', $cctvManager->id)->where('type', 'observation_visit_submitted')->count());
        $this->assertTrue($pending->created_at->lt(now()));
        // Approved by the demo manager of the reporting department when there is one (CCTV), else the super admin.
        $cctvVisit = ObservationVisit::where('status', 'approved')->whereHas('observations', fn ($q) => $q->where('description', 'like', '%35 دقيقة%'))->firstOrFail();
        $this->assertSame($cctvManager->id, (int) $cctvVisit->approved_by);
        $this->assertTrue($cctvVisit->approved_at->gt($cctvVisit->submitted_at));
        $this->assertTrue(ObservationVisit::where('visit_date', '<=', now()->subDays(7)->toDateString())->exists());
        $this->assertTrue(ObservationVisit::where('visit_date', '>=', now()->subDays(3)->toDateString())->exists());
        $this->assertSame(0, ObservationVisit::where('visit_date', '<', now()->subDays(10)->toDateString())->count());

        // The late opening of 35 minutes was escalated to high by the catalog rule.
        $late = Observation::whereHas('catalogItem', fn ($q) => $q->where('code', 'CCTV-OPEN-LATE'))->firstOrFail();
        $this->assertSame(35, (int) $late->extra_values['minutes_late']);
        $this->assertSame('high', RiskLevel::find($late->risk_level_id)->slug);

        // A poor stock assessment became an issue with a ticket; the desk HR visit holds activities only.
        $stockVisit = ObservationVisit::whereHas('department', fn ($q) => $q->where('slug', 'stock_control'))->firstOrFail();
        $this->assertTrue($stockVisit->observations()->where('rating', 'poor')->whereNotNull('ticket_id')->exists());
        $desk = ObservationVisit::where('type', 'desk')->firstOrFail();
        $this->assertNull($desk->branch_id);
        $this->assertSame(['activity'], $desk->observations()->pluck('kind')->unique()->values()->all());

        // Tickets were created by the service, with before and after photos copied into storage.
        $this->assertGreaterThan(0, Ticket::where('source', 'observation')->count());
        $this->assertTrue(Ticket::where('source', 'observation')->get()->contains(fn (Ticket $t) => $t->isOverdue()));
        $after = ObservationPhoto::where('kind', 'after')->firstOrFail();
        Storage::disk('public')->assertExists($after->path);
        Storage::disk('public')->assertExists(ObservationPhoto::where('kind', 'before')->value('path'));
        $this->assertTrue(ObservationVerification::where('result', 'not_resolved')->exists());
    }

    public function test_generate_twice_does_not_duplicate(): void
    {
        $this->generate();
        $counts = [
            Observation::count(),
            ObservationVisit::count(),
            Ticket::where('source', 'observation')->count(),
            ObservationPhoto::count(),
            ObservationVerification::count(),
            AiClassification::count(),
        ];
        $paths = ObservationPhoto::pluck('path')->all();

        $this->generate();

        $this->assertSame($counts, [
            Observation::count(),
            ObservationVisit::count(),
            Ticket::where('source', 'observation')->count(),
            ObservationPhoto::count(),
            ObservationVerification::count(),
            AiClassification::count(),
        ]);
        // The first run's files were removed with its rows.
        foreach ($paths as $path) {
            if (! ObservationPhoto::where('path', $path)->exists()) {
                Storage::disk('public')->assertMissing($path);
            }
        }
    }

    public function test_wipe_removes_observation_data_but_keeps_setup_and_users(): void
    {
        $this->generate();
        $this->assertGreaterThan(0, Observation::count());

        $kept = fn () => [
            'users' => User::count(),
            'branches' => Branch::count(),
            'areas' => Area::count(),
            'departments' => Department::count(),
            'risk_levels' => RiskLevel::count(),
            'categories' => ObservationCategory::count(),
            'catalog' => ObservationCatalogItem::count(),
        ];
        $before = $kept();
        $this->assertGreaterThan(0, $before['catalog']);

        $this->actingAs($this->admin)->from('/dashboard')->post(route('demo.wipe'))
            ->assertRedirect('/dashboard')->assertSessionHas('status');

        $this->assertSame(0, AiClassification::count());
        $this->assertSame(0, ObservationVerification::count());
        $this->assertSame(0, ObservationPhoto::count());
        $this->assertSame(0, Observation::count());
        $this->assertSame(0, ObservationVisit::count());
        $this->assertSame(0, Ticket::where('source', 'observation')->count());
        $this->assertSame([], Storage::disk('public')->allFiles('evidence/observations'));
        $this->assertSame($before, $kept());

        // Generate after a wipe works on the kept setup and never resets the owner's catalog edits.
        $edited = ObservationCatalogItem::where('code', 'CCTV-FIT-OVER-5')->firstOrFail();
        $edited->update(['label_ar' => 'نص عدّله المدير']);
        $this->generate();
        $this->assertSame('نص عدّله المدير', $edited->fresh()->label_ar);
        $this->assertGreaterThan(0, Observation::count());
        $this->assertSame($before['catalog'], ObservationCatalogItem::count());
    }

    public function test_reopen_ticket_reassigned_to_demo_employee_through_service(): void
    {
        // A real operation technician on Saraya, older than the demo users: autoAssign picks them first.
        $operation = Seeds::department('operation');
        $saraya = Seeds::branch('Sheikh Zayed - Saraya Mall', ['active' => true]);
        $real = Seeds::user('department_employee', ['department_id' => $operation->id, 'name' => 'فني حقيقي']);
        $real->branches()->attach($saraya->id);

        $this->generate();

        $reopened = Observation::where('status', 'reopened')->firstOrFail();
        $ticket = Ticket::findOrFail($reopened->ticket_id);
        $demoEmp = User::where('email', 'operation.emp@levoile.test')->firstOrFail();
        $this->assertSame($demoEmp->id, (int) $ticket->assigned_to);
        // The latest assignment log row names the demo employee (written by TicketService::assign) and is backdated.
        $lastAssignment = TicketUpdate::where('ticket_id', $ticket->id)->where('action', 'assignment')->orderByDesc('id')->firstOrFail();
        $this->assertStringContainsString($demoEmp->name, (string) $lastAssignment->note);
        $this->assertTrue($lastAssignment->created_at->lt(now()->subDays(6)));
        // No demo notification is left for the real technician.
        $this->assertSame(0, Notification::where('user_id', $real->id)->count());
    }

    public function test_buttons_hidden_for_non_admin(): void
    {
        $ops = Seeds::user('ops_manager');

        $this->actingAs($ops)->get('/dashboard')->assertOk()
            ->assertDontSee(route('demo.generate'))
            ->assertDontSee(route('demo.wipe'));
        $this->actingAs($ops)->post(route('demo.generate'))->assertForbidden();
        $this->actingAs($ops)->post(route('demo.wipe'))->assertForbidden();
        $this->assertSame(0, ObservationVisit::count());

        // The super admin sees both buttons, each behind its own confirmation modal.
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee(route('demo.generate'))
            ->assertSee(route('demo.wipe'))
            ->assertSee('demoGenerateModal')
            ->assertSee('demoWipeModal')
            ->assertDontSee('return confirm(', false);
    }

    public function test_generate_without_demo_login_creates_no_observations_or_demo_users(): void
    {
        config(['app.demo_login' => false]);
        $users = User::count();

        $this->actingAs($this->admin)->from('/dashboard')->post(route('demo.generate'))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'DEMO_LOGIN'));

        $this->assertSame(0, Observation::count());
        $this->assertSame(0, ObservationVisit::count());
        $this->assertSame($users, User::count());
        $this->assertSame(0, User::where('email', 'like', '%@levoile.test')->count());
    }

    public function test_imported_rows_survive_generate(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $branch = Seeds::branch('Imported Branch', ['active' => true]);
        $importer = Seeds::user('super_admin', ['active' => false]);
        $visit = ObservationVisit::create([
            'type' => 'field', 'department_id' => $cctv->id, 'branch_id' => $branch->id, 'user_id' => $importer->id,
            'visit_date' => now()->subDays(30)->toDateString(), 'status' => 'submitted', 'source' => 'import',
        ]);
        $imported = Observation::create([
            'observation_visit_id' => $visit->id, 'branch_id' => $branch->id, 'reporting_department_id' => $cctv->id,
            'kind' => 'issue', 'title' => 'Imported', 'description' => 'Imported row', 'event_date' => null,
            'created_by' => $importer->id, 'status' => 'open', 'classification_status' => 'manual',
            'import_source' => 'levoile_control_center_oct2026.xlsx', 'import_row' => 7,
        ]);
        $ticket = Ticket::create([
            'reference' => 'TK-IMP01', 'title' => 'Imported ticket', 'branch_id' => $branch->id, 'department_id' => $cctv->id,
            'status' => 'open', 'source' => 'observation', 'observation_id' => $imported->id,
        ]);
        $imported->update(['ticket_id' => $ticket->id]);

        $this->generate();
        $this->generate();

        $this->assertTrue(Observation::whereKey($imported->id)->where('ticket_id', $ticket->id)->exists());
        $this->assertTrue(Ticket::whereKey($ticket->id)->exists());
        $this->assertTrue(ObservationVisit::whereKey($visit->id)->exists());
    }
}
