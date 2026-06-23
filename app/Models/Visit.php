<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Visit $visit) {
            if (empty($visit->code)) {
                $visit->code = static::nextCode();
            }
        });
    }

    public static function nextCode(): string
    {
        $last = static::max('id') ?? 0;

        return 'VS-'.str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'code', 'visit_template_id', 'branch_id', 'user_id', 'status', 'scheduled_date', 'scheduled_time',
        'checked_in_at', 'checkin_latitude', 'checkin_longitude', 'checkin_simulated',
        'started_at', 'completed_at', 'positives_count', 'problems_count',
        'unanswered_count', 'tickets_count', 'score', 'general_comments',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'checked_in_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'checkin_simulated' => 'boolean',
        'score' => 'float',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(VisitTemplate::class, 'visit_template_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VisitAnswer::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isReadOnly(): bool
    {
        return in_array($this->status, ['completed', 'cancelled'], true);
    }
}
