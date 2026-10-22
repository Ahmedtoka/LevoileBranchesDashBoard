<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'job_title', 'staff_code', 'email', 'phone', 'password', 'avatar_path',
        'role_id', 'department_id', 'branch_id', 'is_department_manager', 'active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_department_manager' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Branches this user covers (area manager region / technician branches). */
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_user')->withTimestamps();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    public function managedAreas(): HasMany
    {
        return $this->hasMany(Area::class, 'manager_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    public function hasRole(string|array $slugs): bool
    {
        $slugs = (array) $slugs;

        return in_array(optional($this->role)->slug, $slugs, true);
    }

    /** May record observations: management roles, or any member of a reporting department. */
    public function isReportingUser(): bool
    {
        if ($this->hasRole(['super_admin', 'ops_manager', 'area_manager', 'branch_director', 'vm_manager'])) {
            return true;
        }

        return $this->department_id !== null && (bool) $this->department?->is_reporting;
    }

    /** Reporting department pre-selected when this user records an observation. */
    public function defaultReportingDepartmentId(): ?int
    {
        if ($this->department_id !== null && $this->department?->is_reporting) {
            return (int) $this->department_id;
        }

        $slug = $this->hasRole('vm_manager') ? 'vm' : 'operation';
        $id = Department::where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Branch ids this user is limited to in the observations module; null = not limited by branch.
     * Area managers: branches of the areas they manage plus branch_user. Branch roles: own branch plus managed branches.
     */
    public function scopedBranchIds(): ?array
    {
        if ($this->hasRole('area_manager')) {
            $ids = Branch::whereIn('area_id', Area::where('manager_id', $this->id)->select('id'))->pluck('id')
                ->merge(DB::table('branch_user')->where('user_id', $this->id)->pluck('branch_id'));
        } elseif ($this->hasRole(['store_manager', 'branch_manager'])) {
            $ids = Branch::where('manager_id', $this->id)->pluck('id')->push($this->branch_id)->filter();
        } elseif ($this->hasRole(['super_admin', 'ops_manager', 'branch_director', 'vm_manager', 'department_manager', 'department_employee'])
            || $this->is_department_manager) {
            return null;
        } else {
            return [];
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
