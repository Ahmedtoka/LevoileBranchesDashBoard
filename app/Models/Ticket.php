<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public const STATUSES = [
        'open', 'assigned', 'on_the_way', 'in_progress',
        'waiting_approval', 'postponed', 'not_fixed', 'rejected', 'closed',
    ];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** Arabic status labels. */
    public const STATUS_AR = [
        'open' => 'جديدة', 'assigned' => 'معيّنة', 'on_the_way' => 'مقبولة', 'in_progress' => 'جاري التنفيذ',
        'waiting_approval' => 'بانتظار الموافقة', 'postponed' => 'مؤجّلة', 'not_fixed' => 'لم يتم التصليح',
        'rejected' => 'مرفوضة', 'closed' => 'مقفولة',
    ];

    /**
     * Forward-only cycle: which actions are allowed from each status.
     * [to_status => ['label'=>, 'color'=>]]
     */
    public static function nextActions(string $status): array
    {
        return match ($status) {
            'assigned' => ['on_the_way' => ['label' => 'قبول الطلب', 'color' => 'primary']],
            'on_the_way' => ['in_progress' => ['label' => 'بدء العمل', 'color' => 'warning']],
            'in_progress' => [
                'waiting_approval' => ['label' => 'تم الإصلاح', 'color' => 'success'],
                'postponed' => ['label' => 'تأجيل', 'color' => 'secondary'],
                'not_fixed' => ['label' => 'تعذّر الإصلاح', 'color' => 'danger'],
            ],
            'postponed', 'not_fixed' => ['in_progress' => ['label' => 'استئناف العمل', 'color' => 'warning']],
            'waiting_approval' => [
                'closed' => ['label' => 'موافقة وإقفال', 'color' => 'success'],
                'rejected' => ['label' => 'رفض وإعادة', 'color' => 'danger'],
            ],
            'rejected' => ['in_progress' => ['label' => 'إعادة فتح', 'color' => 'warning']],
            default => [], // open -> handled via assignment; closed -> terminal
        };
    }

    public static function canTransition(string $from, string $to): bool
    {
        return array_key_exists($to, self::nextActions($from));
    }

    public function statusAr(): string
    {
        return self::STATUS_AR[$this->status] ?? $this->status;
    }

    protected $fillable = [
        'reference', 'group_code', 'title', 'description', 'branch_id', 'department_id',
        'visit_id', 'visit_answer_id', 'checklist_question_id', 'created_by',
        'assigned_to', 'status', 'priority', 'sla_hours', 'due_at',
        'assigned_at', 'scheduled_at', 'resolved_at', 'closed_at', 'reopen_count', 'category',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'assigned_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function ageInHours(): int
    {
        $end = $this->closed_at ?? now();

        return (int) $this->created_at->diffInHours($end);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(VisitAnswer::class, 'visit_answer_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ChecklistQuestion::class, 'checklist_question_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TicketUpdate::class)->latest();
    }

    public function isOverdue(): bool
    {
        return $this->due_at
            && $this->due_at->isPast()
            && ! in_array($this->status, ['closed', 'waiting_approval'], true);
    }

    /**
     * Next reference for a given prefix, with its own running sequence.
     * MTN- for maintenance requests, CHK- for checklist-generated tickets.
     */
    public static function nextReference(string $prefix = 'TK'): string
    {
        $last = static::where('reference', 'like', $prefix.'-%')
            ->orderByDesc('id')->value('reference');
        $n = $last ? (int) preg_replace('/\D/', '', substr($last, strlen($prefix) + 1)) : 0;

        return $prefix.'-'.str_pad($n + 1, 4, '0', STR_PAD_LEFT);
    }
}
