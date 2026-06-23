<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitAnswer extends Model
{
    protected $fillable = [
        'visit_id', 'checklist_question_id', 'result', 'value', 'score', 'comment',
    ];

    protected $casts = ['score' => 'float'];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ChecklistQuestion::class, 'checklist_question_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(VisitAnswerEvidence::class);
    }

    public function selectedEmployees(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'visit_answer_selected_employees',
            'visit_answer_id',
            'user_id'
        )->withTimestamps();
    }

    public function isFail(): bool
    {
        return $this->result === 'fail';
    }
}
