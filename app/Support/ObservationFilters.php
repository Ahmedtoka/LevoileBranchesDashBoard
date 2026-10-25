<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The observations register filter set (spec section 10). One object builds the query for the
 * page, its pagination links and the CSV export, so there is a single filtering implementation.
 */
class ObservationFilters
{
    /** Plain equality filters: request key => observations column. */
    private const COLUMNS = [
        'reporting_department_id' => 'reporting_department_id',
        'responsible_department_id' => 'responsible_department_id',
        'branch_id' => 'branch_id',
        'category_id' => 'category_id',
        'status' => 'status',
        'kind' => 'kind',
        'classification_status' => 'classification_status',
    ];

    /** The observations screens' own extra preset (not in the shared DateRange::$presets): no date bound at all. */
    public const ALL = 'all';

    /**
     * The observation's date: event_date, or its visit's date when the row is undated (imported rows).
     * The register, the CSV and every dashboard number filter and sort on this one expression.
     */
    public const DATE_SQL = 'COALESCE(observations.event_date, (SELECT ov.visit_date FROM observation_visits ov WHERE ov.id = observations.observation_visit_id))';

    public DateRange $range;

    /** @var array<string, string> non-empty scalar filters, keyed by request name */
    public array $values = [];

    public function __construct(DateRange $range, array $values)
    {
        $this->range = $range;
        $this->values = $values;
    }

    public static function fromRequest(Request $request): self
    {
        $values = [];
        $keys = array_merge(array_keys(self::COLUMNS), ['area_id', 'risk', 'outside_catalog', 'q']);

        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        if (isset($values['outside_catalog']) && $values['outside_catalog'] !== '1') {
            unset($values['outside_catalog']);
        }

        return new self(self::rangeFromRequest($request), $values);
    }

    /** DateRange::fromRequest plus the `range=all` preset (key `all`, never applied as a bound). */
    public static function rangeFromRequest(Request $request): DateRange
    {
        if ($request->query('range') !== self::ALL) {
            return DateRange::fromRequest($request);
        }

        $range = new DateRange;
        $range->key = self::ALL;
        $range->label = 'All dates';
        $range->to = Carbon::now()->endOfDay();
        $range->from = Carbon::create(2000, 1, 1)->startOfDay(); // for display / week windows only

        return $range;
    }

    public static function isAll(DateRange $range): bool
    {
        return $range->key === self::ALL;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function apply(Builder $query): Builder
    {
        if (! self::isAll($this->range)) {
            // Undated rows fall on their visit's date (DATE_SQL), so they are never silently dropped.
            $query->whereRaw(self::DATE_SQL.' BETWEEN ? AND ?', [$this->range->from, $this->range->to]);
        }

        return $this->applyWithoutRange($query);
    }

    /**
     * Every filter except the date range (for "now" states such as overdue that no period bounds).
     * Also the base scope of every consumer: observations of a draft visit are never listed or counted
     * (they are still being written, and the author's next autosave would undo any admin action on them).
     */
    public function applyWithoutRange(Builder $query): Builder
    {
        $query->whereIn($query->qualifyColumn('observation_visit_id'), self::submittedVisitIds());

        foreach (self::COLUMNS as $key => $column) {
            if (isset($this->values[$key])) {
                $query->where($query->qualifyColumn($column), $this->values[$key]);
            }
        }

        if (isset($this->values['area_id'])) {
            $query->whereHas('branch', fn (Builder $b) => $b->where($b->qualifyColumn('area_id'), $this->values['area_id']));
        }

        if (isset($this->values['risk'])) {
            $query->whereHas('riskLevel', fn (Builder $r) => $r->where($r->qualifyColumn('slug'), $this->values['risk']));
        }

        if (isset($this->values['outside_catalog'])) {
            $query->whereNull($query->qualifyColumn('catalog_item_id'));
        }

        if (isset($this->values['q'])) {
            $like = '%'.addcslashes($this->values['q'], '%_\\').'%';
            $query->where(function (Builder $w) use ($like) {
                foreach (['reference', 'title', 'description'] as $column) {
                    $w->orWhere($w->qualifyColumn($column), 'like', $like);
                }
            });
        }

        return $query;
    }

    /** Ids of the submitted visits, as a subquery (the draft exclusion of applyWithoutRange). */
    public static function submittedVisitIds(): QueryBuilder
    {
        return DB::table('observation_visits')->whereIn('observation_visits.status', ['submitted', 'approved'])->select('observation_visits.id');
    }

    /** Only the active filters (plus the date range), ready for route('observations.index', ...). */
    public function toQueryString(): array
    {
        $query = ['range' => $this->range->key];

        if ($this->range->key === 'custom') {
            $query['from'] = $this->range->customFrom;
            $query['to'] = $this->range->customTo;
        }

        return array_filter($query + $this->values, fn ($v) => $v !== null && $v !== '');
    }
}
