<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ObservationVisit extends Model
{
    public const TYPES = ['field', 'desk'];

    public const STATUSES = ['draft', 'submitted', 'approved'];

    public const SOURCES = ['mobile', 'web', 'import'];

    protected $fillable = [
        'code', 'type', 'department_id', 'branch_id', 'user_id', 'visit_date',
        'started_at', 'submitted_at', 'approved_by', 'approved_at', 'checkin_latitude', 'checkin_longitude',
        'checkin_simulated', 'status', 'general_notes', 'source',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'checkin_simulated' => 'boolean',
        'checkin_latitude' => 'float',
        'checkin_longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (ObservationVisit $visit) {
            $visit->code = $visit->code ?: static::nextCode();
        });
    }

    /** Next OV-00001 style code, derived from the highest existing number (not the auto-increment id or the newest row). */
    public static function nextCode(): string
    {
        $max = (int) static::query()
            ->where('code', 'like', 'OV-%')
            ->max(DB::raw('CAST(SUBSTRING(code, 4) AS UNSIGNED)'));

        return 'OV-'.str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The manager who approved the visit (tickets were created at that moment). */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'observation_visit_id');
    }
}
