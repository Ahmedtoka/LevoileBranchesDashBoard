<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Parses a date-range filter from the request.
 * Keys: today, 3d, week, month, custom (with from/to).
 */
class DateRange
{
    public Carbon $from;

    public Carbon $to;

    public string $key;

    public string $label;

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public static array $presets = [
        'today' => 'Today',
        '3d' => 'Last 3 days',
        'week' => 'Last 7 days',
        'month' => 'Last 30 days',
        'custom' => 'Custom',
    ];

    public static function fromRequest(Request $request, string $default = 'week'): self
    {
        $self = new self;
        $key = $request->query('range', $default);
        if (! array_key_exists($key, self::$presets)) {
            $key = $default;
        }

        $now = Carbon::now();
        $self->to = $now->copy()->endOfDay();

        switch ($key) {
            case 'today':
                $self->from = $now->copy()->startOfDay();
                break;
            case '3d':
                $self->from = $now->copy()->subDays(3)->startOfDay();
                break;
            case 'month':
                $self->from = $now->copy()->subDays(30)->startOfDay();
                break;
            case 'custom':
                $self->customFrom = $request->query('from');
                $self->customTo = $request->query('to');
                $self->from = $self->customFrom ? Carbon::parse($self->customFrom)->startOfDay() : $now->copy()->subDays(7)->startOfDay();
                $self->to = $self->customTo ? Carbon::parse($self->customTo)->endOfDay() : $now->copy()->endOfDay();
                break;
            case 'week':
            default:
                $key = 'week';
                $self->from = $now->copy()->subDays(7)->startOfDay();
                break;
        }

        $self->key = $key;
        $self->label = self::$presets[$key];

        return $self;
    }

    /** Apply the range to a query column. */
    public function apply($query, string $column = 'created_at')
    {
        return $query->whereBetween($column, [$this->from, $this->to]);
    }

    public function days(): int
    {
        return max(1, $this->from->diffInDays($this->to) + 1);
    }
}
