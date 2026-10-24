<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ObservationFilters;
use Illuminate\Http\Request;
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
            ->assertSee('In high')->assertSee('In low closed')->assertDontSee('Out high')
            ->assertSee('Branch In')->assertDontSee('Branch Out'); // dropdowns are scoped too

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

    /** Titles the register lists for the given query, sorted. */
    private function titles(User $user, array $params = []): array
    {
        $titles = $this->actingAs($user)->get(route('observations.index', $params))->assertOk()
            ->viewData('observations')->pluck('title')->all();
        sort($titles);

        return $titles;
    }

    public function test_date_range_defaults_to_week_and_widens_with_month(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $this->observation($branch, ['title' => 'Recent']);
        $this->observation($branch, ['title' => 'Twenty days old', 'event_date' => now()->subDays(20)->toDateString()]);
        $admin = Seeds::user('super_admin');

        $this->assertSame(['Recent'], $this->titles($admin));
        $this->assertSame(['Recent'], $this->titles($admin, ['range' => 'week']));
        $this->assertSame(['Recent', 'Twenty days old'], $this->titles($admin, ['range' => 'month']));
        $this->assertSame(['Twenty days old'], $this->titles($admin, [
            'range' => 'custom', 'from' => now()->subDays(25)->toDateString(), 'to' => now()->subDays(15)->toDateString(),
        ]));
    }

    public function test_area_filter_excludes_other_areas_and_branches_without_area(): void
    {
        $east = Area::create(['name' => 'East', 'slug' => 'east']);
        $west = Area::create(['name' => 'West', 'slug' => 'west']);
        $this->observation(Seeds::branch('East Branch', ['area_id' => $east->id]), ['title' => 'East obs']);
        $this->observation(Seeds::branch('West Branch', ['area_id' => $west->id]), ['title' => 'West obs']);
        $this->observation(Seeds::branch('No Area Branch'), ['title' => 'No area obs']);
        $admin = Seeds::user('super_admin');

        $this->assertSame(['East obs'], $this->titles($admin, ['area_id' => $east->id]));
        $this->assertSame(['West obs'], $this->titles($admin, ['area_id' => $west->id]));
        $this->assertCount(3, $this->titles($admin));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $this->observation($branch, ['title' => 'Discount 50% off']);
        $this->observation($branch, ['title' => 'Discount 500 off']);
        $this->observation($branch, ['title' => 'snake_case', 'description' => 'x']);
        $this->observation($branch, ['title' => 'snakeXcase', 'description' => 'x']);
        $this->observation($branch, ['title' => 'Described', 'description' => 'find the needle here']);
        $admin = Seeds::user('super_admin');

        $this->assertSame(['Discount 50% off'], $this->titles($admin, ['q' => '50%']));
        $this->assertSame(['snake_case'], $this->titles($admin, ['q' => 'ke_ca']));
        $this->assertSame(['Described'], $this->titles($admin, ['q' => 'needle']));
        $first = Observation::where('title', 'Discount 50% off')->first();
        $this->assertSame(['Discount 50% off'], $this->titles($admin, ['q' => $first->reference]));
    }

    public function test_department_category_and_classification_filters_narrow(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $maintenance = Seeds::department('maintenance');
        $cat = fn (string $slug) => ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug]);
        $cameras = $cat('cameras');
        $doors = $cat('doors');

        $this->observation($branch, ['title' => 'A', 'category_id' => $cameras->id, 'responsible_department_id' => $maintenance->id, 'classification_status' => 'catalog']);
        $this->observation($branch, ['title' => 'B', 'category_id' => $doors->id, 'classification_status' => 'manual']);
        $this->observation($branch, ['title' => 'C', 'reporting_department_id' => $stock->id, 'classification_status' => 'ai_pending_review']);
        $admin = Seeds::user('super_admin');

        $this->assertSame(['A', 'B'], $this->titles($admin, ['reporting_department_id' => $this->cctv->id]));
        $this->assertSame(['C'], $this->titles($admin, ['reporting_department_id' => $stock->id]));
        $this->assertSame(['A'], $this->titles($admin, ['responsible_department_id' => $maintenance->id]));
        $this->assertSame(['B'], $this->titles($admin, ['category_id' => $doors->id]));
        $this->assertSame(['B'], $this->titles($admin, ['classification_status' => 'manual']));
        $this->assertSame(['C'], $this->titles($admin, ['classification_status' => 'ai_pending_review']));
        $this->assertSame(['A'], $this->titles($admin, ['category_id' => $cameras->id, 'classification_status' => 'catalog']));
        $this->assertSame([], $this->titles($admin, ['category_id' => $doors->id, 'classification_status' => 'catalog']));
    }

    public function test_to_query_string_round_trips_and_drops_empties(): void
    {
        $request = Request::create('/observations', 'GET', [
            'range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'risk' => 'high', 'status' => '', 'q' => '  needle  ', 'outside_catalog' => '1', 'branch_id' => '7', 'kind' => '   ',
        ]);
        $filters = ObservationFilters::fromRequest($request);
        $qs = $filters->toQueryString();

        $this->assertSame([
            'range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'branch_id' => '7', 'risk' => 'high', 'outside_catalog' => '1', 'q' => 'needle',
        ], $qs);

        $again = ObservationFilters::fromRequest(Request::create('/observations', 'GET', $qs));
        $this->assertSame($filters->values, $again->values);
        $this->assertSame($qs, $again->toQueryString());

        // Default range survives too, and a bogus outside_catalog value is dropped.
        $plain = ObservationFilters::fromRequest(Request::create('/observations', 'GET', ['outside_catalog' => 'yes']));
        $this->assertSame(['range' => 'week'], $plain->toQueryString());
    }

    public function test_dropdowns_are_scoped_to_the_users_branches_and_areas(): void
    {
        [$am] = $this->areaWorld();
        Area::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);

        $res = $this->actingAs($am)->get(route('observations.index'))->assertOk()
            ->assertSee('Branch In')->assertSee('East')
            ->assertDontSee('Branch Out')->assertDontSee('Elsewhere');
        $this->assertCount(1, $res->viewData('branches'));
        $this->assertCount(1, $res->viewData('areas'));

        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.index'))
            ->assertSee('Branch In')->assertSee('Branch Out')->assertSee('Elsewhere');
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $branch = Seeds::branch('=HYPERLINK("http://x")');
        $this->observation($branch, ['title' => '@SUM(A1)']);
        $this->observation($branch, ['title' => '-5 degrees']);
        $this->observation($branch, ['title' => 'Safe title']);

        $rows = $this->csvRows($this->actingAs(Seeds::user('super_admin'))->get(route('observations.export'))->streamedContent());
        $titles = array_column($rows, 5);

        $this->assertContains("'@SUM(A1)", $titles);
        $this->assertContains("'-5 degrees", $titles);
        $this->assertContains('Safe title', $titles);
        $this->assertSame('\'=HYPERLINK("http://x")', $rows[1][2]);
    }
}
