<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistQuestion extends Model
{
    protected $fillable = [
        'checklist_section_id', 'question_text', 'question_text_ar', 'type', 'input_type', 'answer_types',
        'pass_config', 'fail_config', 'options', 'options_style',
        'max_score', 'responsible_department_id', 'responsible_department_ids', 'comment_required_on_fail',
        'photo_required_on_fail', 'auto_create_ticket_on_fail', 'is_people_issue',
        'default_priority', 'sla_hours', 'sort_order',
    ];

    protected $casts = [
        'answer_types' => 'array',
        'pass_config' => 'array',
        'fail_config' => 'array',
        'responsible_department_ids' => 'array',
        'options' => 'array',
        'comment_required_on_fail' => 'boolean',
        'photo_required_on_fail' => 'boolean',
        'auto_create_ticket_on_fail' => 'boolean',
        'is_people_issue' => 'boolean',
    ];

    /** Components to render. Falls back to the legacy single `type`. */
    public function getAnswerTypesListAttribute(): array
    {
        return ! empty($this->answer_types) ? $this->answer_types : [$this->type];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ChecklistSection::class, 'checklist_section_id');
    }

    public function responsibleDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    /** All responsible department ids (multi), falling back to the single column. */
    public function departmentIds(): array
    {
        $ids = $this->responsible_department_ids;
        if (! empty($ids)) {
            return array_values(array_unique(array_map('intval', $ids)));
        }

        return $this->responsible_department_id ? [(int) $this->responsible_department_id] : [];
    }

    public function responsibleDepartments()
    {
        return Department::whereIn('id', $this->departmentIds())->get();
    }
}
