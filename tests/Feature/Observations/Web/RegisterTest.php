<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    private Department $cctv;

    private User $creator;

    private RiskLevel $high;

    private RiskLevel $low;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->creator = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#dc2626', 'sort_order' => 2]);
        $this->low = RiskLevel::create(['slug' => 'low', 'name_ar' => 'منخفض', 'name_en' => 'Low', 'color' => '#16a34a', 'sort_order' => 4]);
    }

    private function observation(Branch $branch, array $attrs = []): Observation
    {
        $visit = ObservationVisit::create([
            'type' => 'field',
            'department_id' => $this->cctv->id,
            'branch_id' => $branch->id,
            'user_id' => $this->creator->id,
            'visit_date' => now()->toDateString(),
            'source' => 'web',
        ]);

        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'reporting_department_id' => $this->cctv->id,
            'kind' => 'issue',
            'title' => 'Observation',
            'description' => 'Observation description',
            'event_date' => now()->toDateString(),
            'created_by' => $this->creator->id,
            'status' => 'open',
            'classification_status' => 'catalog',
        ], $attrs));
    }

    /** @return array{0: User, 1: Branch, 2: Branch} area manager, branch inside the area, branch outside it */
    private function areaWorld(): array
    {
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);

        return [$am, Seeds::branch('Branch In', ['area_id' => $area->id]), Seeds::branch('Branch Out')];
    }

    private function csvRows(string $body): array
    {
        $body = substr($body, 3); // UTF-8 BOM
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body))));

        return array_map('str_getcsv', $lines);
    }

    public function test_index_scoped_and_filtered(): void
    {
        [$am, $inside, $outside] = $this->areaWorld();
        $this->observation($inside, ['title' => 'In high', 'risk_level_id' => $this->high->id]);
        $this->observation($inside, ['title' => 'In low closed', 'risk_level_id' => $this->low->id, 'status' => 'verified_closed']);
        $this->observation($outside, ['title' => 'Out high', 'risk_level_id' => $this->high->id]);

        $this->actingAs($am)->get(route('observations.index'))
            ->assertOk()
            ->assertSee('In high')->assertSee('In low closed')->assertDontSee('Out high');

        $this->actingAs($am)->get(route('observations.index', ['risk' => 'high']))
            ->assertOk()->assertSee('In high')->assertDontSee('In low closed')->assertDontSee('Out high');

        $this->actingAs($am)->get(route('observations.index', ['status' => 'verified_closed']))
            ->assertOk()->assertSee('In low closed')->assertDontSee('In high');

        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.index'))
            ->assertOk()->assertSee('In high')->assertSee('In low closed')->assertSee('Out high');
    }

    public function test_index_denied_for_users_without_access(): void
    {
        $this->actingAs(Seeds::user('sales'))->get(route('observations.index'))->assertForbidden();
        $this->actingAs(Seeds::user('sales'))->get(route('observations.export'))->assertForbidden();
    }

    public function test_index_shows_ticket_and_overdue_flag(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $obs = $this->observation($branch, ['risk_level_id' => $this->high->id]);
        $ticket = Ticket::create([
            'reference' => 'TK-R0001', 'title' => 'Ticket', 'branch_id' => $branch->id,
            'department_id' => $this->cctv->id, 'status' => 'open', 'observation_id' => $obs->id,
            'due_at' => now()->subDay(),
        ]);
        $obs->update(['ticket_id' => $ticket->id]);

        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.index'))
            ->assertOk()
            ->assertSee('TK-R0001')
            ->assertSee(route('tickets.show', $ticket), false)
            ->assertSee('متأخر')
            ->assertSee('/observations/'.$obs->id, false);
    }

    public function test_filters_combine_and_survive_pagination(): void
    {
        $branch = Seeds::branch('Heliopolis');
        for ($i = 1; $i <= 32; $i++) {
            $this->observation($branch, ['title' => "Row $i", 'catalog_item_id' => null]);
        }
        $this->observation($branch, ['title' => 'Other kind', 'kind' => 'positive', 'status' => 'positive']);

        $res = $this->actingAs(Seeds::user('super_admin'))
            ->get(route('observations.index', ['kind' => 'issue', 'outside_catalog' => 1, 'branch_id' => $branch->id]))
            ->assertOk()->assertDontSee('Other kind');

        $html = html_entity_decode($res->getContent());
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('kind=issue', $html);
        $this->assertStringContainsString('outside_catalog=1', $html);
    }

    public function test_filters_apply_to_export(): void
    {
        [$am, $inside, $outside] = $this->areaWorld();
        $this->observation($inside, ['title' => 'In high', 'risk_level_id' => $this->high->id]);
        $this->observation($inside, ['title' => 'In high 2', 'risk_level_id' => $this->high->id]);
        $this->observation($inside, ['title' => 'In low', 'risk_level_id' => $this->low->id]);
        $this->observation($outside, ['title' => 'Out high', 'risk_level_id' => $this->high->id]);

        $params = ['risk' => 'high'];

        $page = $this->actingAs($am)->get(route('observations.index', $params))->assertOk();
        $this->assertSame(2, $page->viewData('observations')->total());

        $export = $this->actingAs($am)->get(route('observations.export', $params));
        $export->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('content-type'));
        $body = $export->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $rows = $this->csvRows($body);
        $this->assertCount(1 + 2, $rows, 'header + the filtered rows only');
        $this->assertCount(11, $rows[0]);
        $this->assertStringContainsString('In high', $body);
        $this->assertStringNotContainsString('In low', $body);
        $this->assertStringNotContainsString('Out high', $body);

        // Unfiltered export of the same user still respects scope.
        $all = $this->csvRows($this->actingAs($am)->get(route('observations.export'))->streamedContent());
        $this->assertCount(1 + 3, $all);
    }
}
