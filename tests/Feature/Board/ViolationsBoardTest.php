<?php

namespace Tests\Feature\Board;

use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Illuminate\Support\Carbon;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-11-03: the management board of every department's violations by branch. */
class ViolationsBoardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_board_counts_each_branch_by_department_and_lists_what_is_open(): void
    {
        $operation = Seeds::department('operation', ['is_reporting' => true]);
        RiskLevel::create(['slug' => 'medium', 'name_ar' => 'm', 'name_en' => 'm', 'color' => '#000', 'sort_order' => 2, 'default_sla_hours' => 72, 'ticket_priority' => 'medium', 'active' => true]);
        $zayed = Seeds::branch('Sheikh Zayed - Saraya Mall', ['active' => true]);
        $city = Seeds::branch('Nasr City - City Stars Mall', ['active' => true]);

        $record = function (string $slug, string $route, \Closure $payload) use ($operation) {
            $dept = Seeds::department($slug, ['is_reporting' => true]);
            $cat = ObservationCategory::firstOrCreate(['department_id' => $dept->id, 'slug' => 'x'], ['name_ar' => 'x', 'name_en' => 'x', 'default_kind' => 'issue', 'active' => true]);
            $item = ObservationCatalogItem::firstOrCreate(['code' => strtoupper($slug).'-X'], ['category_id' => $cat->id, 'label_ar' => $slug.' x', 'label_en' => 'x', 'kind' => 'issue', 'risk_level_id' => RiskLevel::bySlug('medium')->id, 'responsible_department_id' => $operation->id]);
            $manager = Seeds::user('department_manager', ['department_id' => $dept->id, 'is_department_manager' => true]);
            $this->actingAs($manager)->post(route($route), $payload($cat->id, $item->id) + ['department_id' => $operation->id])->assertSessionHasNoErrors();

            return $dept;
        };
        $cctvItem = fn ($branch, $date) => fn ($cat, $item) => ['branch_id' => $branch->id, 'event_date' => $date, 'category_id' => $cat, 'catalog_item_id' => $item];
        $cctv = $record('cctv', 'cctv.store', $cctvItem($zayed, '2026-10-09'));
        $record('cctv', 'cctv.store', $cctvItem($zayed, '2026-10-10'));
        $record('cctv', 'cctv.store', $cctvItem($city, '2026-10-16'));
        $record('cctv', 'cctv.store', $cctvItem($city, '2026-09-20'));
        $finance = $record('finance', 'finance.store', fn ($cat, $item) => ['branch_id' => $zayed->id, 'review_date' => '2026-10-20',
            'problems' => [['category_id' => $cat, 'catalog_item_id' => $item, 'department_id' => Seeds::department('operation')->id]]]);

        $page = $this->actingAs(Seeds::user('ops_manager'))->get(route('board.index', ['month' => '2026-10']))->assertOk();
        $rows = $page->viewData('rows')->keyBy('id');
        $this->assertSame([$cctv->id => 2, $finance->id => 1], $rows[$zayed->id]['cells']);
        $this->assertSame(3, $rows[$zayed->id]['total']);
        $this->assertSame(2, $rows[$city->id]['open'], 'open counts every date: September too');
        $this->assertSame($zayed->id, $page->viewData('rows')->first()['id'], 'most violations first');
        $this->assertSame(['total' => 4, 'prev' => 1, 'open' => 5], array_intersect_key($page->viewData('kpi'), array_flip(['total', 'prev', 'open'])));
        $this->assertSame([$operation->id => 5], $page->viewData('openByResponsible'));

        $week2 = $this->actingAs(Seeds::user('ops_manager'))->get(route('board.index', ['month' => '2026-10', 'week' => 2]))->viewData('kpi');
        $this->assertSame(2, $week2['total']);
        $this->assertSame(1, $this->actingAs(Seeds::user('ops_manager'))->get(route('board.index', ['month' => '2026-10', 'department_id' => $finance->id]))->viewData('kpi')['total']);

        $this->actingAs(Seeds::user('department_employee', ['department_id' => $operation->id]))->get(route('board.index'))->assertForbidden();
    }
}
