<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Inactive staff cannot sign in and are signed out on their next request.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * @return HasMany<StaffAssignment, $this>
     */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class);
    }

    /**
     * Departments the user is actively assigned to (department scope).
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'staff_assignments')
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProviderSchedule, $this>
     */
    public function providerSchedules(): HasMany
    {
        return $this->hasMany(ProviderSchedule::class, 'provider_id');
    }

    /**
     * @return HasMany<ProviderScheduleException, $this>
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ProviderScheduleException::class, 'provider_id');
    }

    public function isAssignedToDepartment(int $departmentId): bool
    {
        return $this->departments()->whereKey($departmentId)->exists();
    }

    /**
     * Whether the user holds a role that can deliver services (and so hold schedules).
     */
    public function isProvider(): bool
    {
        return $this->hasAnyRole(array_map(static fn (Role $role): string => $role->value, Role::providerRoles()));
    }

    /**
     * Active staff holding a provider role.
     *
     * @param  Builder<User>  $query
     */
    public function scopeProviders(Builder $query): void
    {
        $query->where('is_active', true)
            ->role(array_map(static fn (Role $role): string => $role->value, Role::providerRoles()));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
