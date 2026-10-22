<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationVerification extends Model
{
    public const RESULTS = ['resolved', 'not_resolved', 'partially'];

    protected $fillable = [
        'observation_id', 'verified_by', 'observation_visit_id', 'result', 'notes', 'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ObservationVisit::class, 'observation_visit_id');
    }
}
