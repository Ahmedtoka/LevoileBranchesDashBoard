<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    public const JOB_TITLES = ['بائعة', 'بائع', 'كاشير', 'مدير فرع', 'نائب مدير فرع', 'مسؤول فرع', 'أمن', 'أخرى'];

    protected $fillable = ['code', 'name', 'branch_id', 'job_title', 'active', 'manager_code', 'manager_name',
        'section', 'department', 'sector', 'work_location', 'phone', 'email'];

    protected $casts = ['active' => 'boolean'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /**
     * The form two spellings of one Arabic name share: alef variants, taa marbuta, alef maqsura and spaces
     * folded ("عبد الرحمن" = "عبدالرحمن", "حبيبة" = "حبيبه").
     */
    public static function normalizeName(string $name): string
    {
        $name = str_replace(['إ', 'أ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], trim($name));

        return preg_replace('/\s+/u', '', $name) ?? $name;
    }
}
