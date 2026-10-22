<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationPhoto extends Model
{
    public const KINDS = ['before', 'after', 'other'];

    public const SOURCES = ['camera', 'gallery'];

    protected $fillable = ['observation_id', 'path', 'kind', 'source', 'uploaded_by'];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
