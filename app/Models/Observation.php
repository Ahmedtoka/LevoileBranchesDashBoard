<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Observation extends Model
{
    public const KINDS = ['issue', 'activity', 'assessment', 'positive'];

    public const STATUSES = [
        'open', 'in_progress', 'waiting_verification', 'verified_closed',
        'reopened', 'closed_no_action', 'positive',
    ];

    public const CLASSIFICATION = ['catalog', 'manual', 'ai_pending_review', 'ai_reviewed'];

    protected $fillable = [
        'reference', 'observation_visit_id', 'branch_id', 'reporting_department_id',
        'responsible_department_id', 'cc_department_ids', 'category_id', 'catalog_item_id',
        'kind', 'risk_level_id', 'risk_override_reason', 'title', 'description',
        'product_or_area', 'extra_values', 'rating', 'event_date', 'ticket_id', 'status',
        'classification_status', 'classification_reviewed_by', 'classification_reviewed_at',
        'repeat_count', 'escalated_from_risk_level_id', 'created_by', 'import_source', 'import_row',
    ];

    protected $casts = [
        'extra_values' => 'array',
        'cc_department_ids' => 'array',
        'event_date' => 'date',
        'classification_reviewed_at' => 'datetime',
        'repeat_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Observation $observation) {
            $observation->reference = $observation->reference ?: static::nextReference();
        });
    }

    /** Next OB-00001 style reference, derived from the highest existing number (not the auto-increment id or the newest row). */
    public static function nextReference(): string
    {
        $max = (int) static::query()
            ->where('reference', 'like', 'OB-%')
            ->max(DB::raw('CAST(SUBSTRING(reference, 4) AS UNSIGNED)'));

        return 'OB-'.str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ObservationVisit::class, 'observation_visit_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reportingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'reporting_department_id');
    }

    public function responsibleDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ObservationCategory::class, 'category_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ObservationCatalogItem::class, 'catalog_item_id');
    }

    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'risk_level_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /** All tickets raised for this observation, including those created by reopens. */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'observation_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ObservationPhoto::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ObservationVerification::class);
    }

    public function classification(): HasOne
    {
        // The latest suggestion linked to this observation (a re-check links a newer row).
        return $this->hasOne(AiClassification::class, 'observation_id')->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
