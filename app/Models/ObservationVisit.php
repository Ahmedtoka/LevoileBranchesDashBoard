<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObservationVisit extends Model
{
    public const TYPES = ['field', 'desk'];

    public const STATUSES = ['draft', 'submitted'];

    public const SOURCES = ['mobile', 'web', 'import'];

    protected $fillable = [
        'code', 'type', 'department_id', 'branch_id', 'user_id', 'visit_date',
        'started_at', 'submitted_at', 'checkin_latitude', 'checkin_longitude',
        'checkin_simulated', 'status', 'general_notes', 'source',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
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

    /** Next OV-00001 style code, derived from the highest existing code (not the auto-increment id). */
    public static function nextCode(): string
    {
        $last = static::where('code', 'like', 'OV-%')->orderByDesc('id')->value('code');
        $n = $last ? (int) preg_replace('/\D/', '', substr($last, 3)) : 0;

        return 'OV-'.str_pad($n + 1, 5, '0', STR_PAD_LEFT);
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

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'observation_visit_id');
    }
}
