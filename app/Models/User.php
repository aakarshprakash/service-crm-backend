<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Users are not globally tenant-scoped (auth must resolve them before the tenant
 * is known). Tenant-side queries must go through User::inTenant().
 */
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role', 'branch_id', 'status', 'photo_path',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'punched_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function scopeInTenant(Builder $query, ?int $tenantId = null): Builder
    {
        $tenantId ??= app(TenantContext::class)->id();

        return $tenantId ? $query->where('users.tenant_id', $tenantId) : $query->whereRaw('1 = 0');
    }

    public function scopeTechnicians(Builder $query): Builder
    {
        return $query->where('role', Role::Technician->value);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'assigned_technician_id');
    }

    /** Areas a technician covers; used to auto-assign jobs. */
    public function serviceLocations(): BelongsToMany
    {
        return $this->belongsToMany(ServiceLocation::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isTechnician(): bool
    {
        return $this->role === Role::Technician;
    }

    public function isCustomer(): bool
    {
        return $this->role === Role::Customer;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }

    /** Abilities from config/permissions.php for this user's role. */
    public function abilities(): array
    {
        $role = $this->role->value;

        return array_keys(array_filter(config('permissions'), fn ($roles) => in_array($role, $roles, true)));
    }
}
