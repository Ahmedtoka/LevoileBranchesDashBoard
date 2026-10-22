<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiClassification extends Model
{
    public const STATUSES = ['ok', 'failed', 'skipped'];

    protected $fillable = [
        'observation_visit_id', 'observation_id', 'request_hash', 'input_text',
        'suggested', 'employee_final', 'admin_final', 'model',
        'input_tokens', 'output_tokens', 'latency_ms', 'status', 'error',
    ];

    protected $casts = [
        'suggested' => 'array',
        'employee_final' => 'array',
        'admin_final' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ObservationVisit::class, 'observation_visit_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}
