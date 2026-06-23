<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitAnswerEvidence extends Model
{
    protected $table = 'visit_answer_evidence';

    protected $fillable = ['visit_answer_id', 'path', 'kind', 'source'];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(VisitAnswer::class, 'visit_answer_id');
    }

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        return asset('storage/'.$this->path);
    }
}
