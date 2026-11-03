<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The CCTV summary page's numbers (owner request 2026-11-03): one month, optionally one of its four weeks
 * (1-7, 8-14, 15-21, 22-end), optionally one branch, each compared with the period before it.
 */
class CctvDashboard
{
    public const WEEKS = 4;

    /** Month as YYYY-MM; week 1-4 or null for the whole month. */
    public function __construct(public readonly User $user, public readonly Carbon $month, public readonly ?int $week, public readonly ?int $branchId) {}

    /** The latest month with a CCTV violation the user can see (this month when there is none). */
    public static function defaultMonth(User $u, ?int $branchId = null): Carbon
    {
        $last = CctvReport::query($u, null, null, $branchId)->max('observations.event_date');

        return Carbon::parse($last ?? now())->startOfMonth();
    }

    /** @return array{0: Carbon, 1: Carbon} first and last day of week $n (1-4) of $month; week 4 runs to the month's end. */
    public static function weekRange(Carbon $month, int $n): array
    {
        $from = $month->copy()->startOfMonth()->addDays(($n - 1) * 7);
        $to = $n >= self::WEEKS ? $month->copy()->endOfMonth()->startOfDay() : $from->copy()->addDays(6);

        return [$from, $to];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        return $this->week ? self::weekRange($this->month, $this->week) : [$this->month->copy()->startOfMonth(), $this->month->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * The period compared against: the month before, or the week before (week 1 against week 4 of the month before).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function previousRange(): array
    {
        if (! $this->week) {
            $m = $this->month->copy()->subMonthNoOverflow();

            return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()->startOfDay(), self::monthName($m)];
        }
        [$month, $week] = $this->week === 1 ? [$this->month->copy()->subMonthNoOverflow(), self::WEEKS] : [$this->month, $this->week - 1];
        [$from, $to] = self::weekRange($month, $week);

        return [$from, $to, dt('الأسبوع ', 'week ').$week.($week === self::WEEKS && $this->week === 1 ? ' · '.self::monthName($month) : '')];
    }

    public function base(): Builder
    {
        [$from, $to] = $this->range();

        return CctvReport::query($this->user, $from, $to, $this->branchId);
    }

    public function previousBase(): Builder
    {
        [$from, $to] = $this->previousRange();

        return CctvReport::query($this->user, $from, $to, $this->branchId);
    }

    /** @return array<int, int> category id => count */
    public static function byCategory(Builder $base): array
    {
        return (clone $base)->selectRaw('observations.category_id AS c, COUNT(*) AS n')->groupBy('observations.category_id')
            ->toBase()->pluck('n', 'c')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> branch id => count */
    public static function byBranch(Builder $base): array
    {
        return (clone $base)->selectRaw('observations.branch_id AS b, COUNT(*) AS n')->groupBy('observations.branch_id')
            ->toBase()->pluck('n', 'b')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> employee id => count (named employees only) */
    public static function byEmployee(Builder $base): array
    {
        return (clone $base)->whereNotNull('observations.employee_id')->selectRaw('observations.employee_id AS e, COUNT(*) AS n')
            ->groupBy('observations.employee_id')->toBase()->pluck('n', 'e')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The employees with the most violations: name, branch, count, the change against the previous period
     * and their most repeated violation.
     *
     * @return list<array{id:int, name:string, branch_id:int, branch:string, n:int, prev:int, top:?string, top_n:int}>
     */
    public function topEmployees(int $limit = 10): array
    {
        $rows = (clone $this->base())->join('employees', 'employees.id', '=', 'observations.employee_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->selectRaw('employees.id, employees.name, employees.branch_id, branches.branch_name, COUNT(*) AS n')
            ->groupBy('employees.id', 'employees.name', 'employees.branch_id', 'branches.branch_name')
            ->orderByDesc('n')->orderBy('employees.name')->limit($limit)->toBase()->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $prev = self::byEmployee($this->previousBase());
        $items = (clone $this->base())->whereIn('observations.employee_id', $rows->pluck('id'))
            ->leftJoin('observation_catalog_items as ci', 'ci.id', '=', 'observations.catalog_item_id')
            ->selectRaw('observations.employee_id AS e, COALESCE(ci.label_ar, observations.title) AS label, COUNT(*) AS n')
            ->groupBy('observations.employee_id', 'ci.label_ar', 'observations.title')->toBase()->get()
            ->groupBy('e')->map(fn ($g) => $g->sortByDesc('n')->first());

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => (string) $r->name, 'branch_id' => (int) $r->branch_id,
            'branch' => CctvReport::storeName($r->branch_name), 'n' => (int) $r->n, 'prev' => $prev[$r->id] ?? 0,
            'top' => $items[$r->id]->label ?? null, 'top_n' => (int) ($items[$r->id]->n ?? 0),
        ])->values()->all();
    }

    /**
     * The branches with the most violations, with the change against the previous period.
     *
     * @return list<array{id:int, name:string, n:int, prev:int}>
     */
    public function topBranches(int $limit = 5): array
    {
        $now = self::byBranch($this->base());
        $prev = self::byBranch($this->previousBase());
        arsort($now);
        $names = \App\Models\Branch::whereIn('id', array_keys($now))->pluck('branch_name', 'id');

        return collect($now)->take($limit)->map(fn ($n, $id) => [
            'id' => (int) $id, 'name' => CctvReport::storeName($names[$id] ?? null), 'n' => $n, 'prev' => $prev[$id] ?? 0,
        ])->values()->all();
    }

    /**
     * The violations (catalog items) seen most often, for the one-branch view.
     *
     * @return list<array{label:string, n:int, prev:int}>
     */
    public function topViolations(int $limit = 5): array
    {
        $count = fn (Builder $b) => (clone $b)->leftJoin('observation_catalog_items as ci', 'ci.id', '=', 'observations.catalog_item_id')
            ->selectRaw('COALESCE(ci.label_ar, observations.title) AS label, COUNT(*) AS n')
            ->groupBy('ci.label_ar', 'observations.title')->toBase()->get()
            ->groupBy('label')->map(fn ($g) => (int) $g->sum('n'));
        $now = $count($this->base())->sortDesc();
        $prev = $count($this->previousBase());

        return $now->take($limit)->map(fn ($n, $label) => ['label' => (string) $label, 'n' => $n, 'prev' => $prev[$label] ?? 0])->values()->all();
    }

    /**
     * The month's four weeks, each split by category, with the change against the week before
     * (week 1 against week 4 of the month before).
     *
     * @return list<array{n:int, from:Carbon, to:Carbon, cells:array<int,int>, total:int, prev:int}>
     */
    public function weeks(): array
    {
        $out = [];
        [$pf, $pt] = self::weekRange($this->month->copy()->subMonthNoOverflow(), self::WEEKS);
        $prev = array_sum(self::byCategory(CctvReport::query($this->user, $pf, $pt, $this->branchId)));
        for ($n = 1; $n <= self::WEEKS; $n++) {
            [$from, $to] = self::weekRange($this->month, $n);
            $cells = self::byCategory(CctvReport::query($this->user, $from, $to, $this->branchId));
            $total = array_sum($cells);
            $out[] = ['n' => $n, 'from' => $from, 'to' => $to, 'cells' => $cells, 'total' => $total, 'prev' => $prev, 'future' => $from->isAfter(now())];
            $prev = $total;
        }

        return $out;
    }

    /** @return array<string, int> every day of the period (Y-m-d) => count, zero days included */
    public function byDay(): array
    {
        [$from, $to] = $this->range();
        $counts = (clone $this->base())->selectRaw('observations.event_date AS d, COUNT(*) AS n')->groupBy('observations.event_date')
            ->toBase()->pluck('n', 'd')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = $counts[$d->toDateString()] ?? 0;
        }

        return $days;
    }

    /** Percentage change, or null when there is nothing to compare against. */
    public static function change(int $now, int $prev): ?int
    {
        return $prev > 0 ? (int) round(($now - $prev) * 100 / $prev) : null;
    }

    public static function monthName(Carbon $m): string
    {
        $ar = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        return app()->getLocale() === 'en' ? $m->format('F Y') : $ar[$m->month - 1].' '.$m->year;
    }
}
