<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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

        return new self(DateRange::fromRequest($request), $values);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function apply(Builder $query): Builder
    {
        $this->range->apply($query, $query->qualifyColumn('event_date'));

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
