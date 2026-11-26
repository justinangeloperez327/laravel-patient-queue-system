<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TriageMode;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property string $queue_prefix
 * @property TriageMode $triage_mode
 * @property int|null $triage_service_id
 * @property bool $allows_appointments
 * @property int $estimated_duration_minutes
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Department $department
 * @property-read Service|null $triageService
 */
#[Fillable([
    'department_id',
    'name',
    'queue_prefix',
    'triage_mode',
    'triage_service_id',
    'allows_appointments',
    'estimated_duration_minutes',
    'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'triage_mode' => 'none',
        'allows_appointments' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triage_mode' => TriageMode::class,
            'allows_appointments' => 'boolean',
            'estimated_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The triage service patients are routed through first, if any.
     *
     * @return BelongsTo<Service, $this>
     */
    public function triageService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'triage_service_id');
    }

    /**
     * Services that route their patients through this (triage) service.
     *
     * @return HasMany<Service, $this>
     */
    public function routedServices(): HasMany
    {
        return $this->hasMany(Service::class, 'triage_service_id');
    }

    /**
     * @return BelongsToMany<Counter, $this>
     */
    public function counters(): BelongsToMany
    {
        return $this->belongsToMany(Counter::class, 'counter_services')->withPivot('department_id');
    }

    /**
     * @return HasMany<ProviderSchedule, $this>
     */
    public function providerSchedules(): HasMany
    {
        return $this->hasMany(ProviderSchedule::class);
    }

    /**
     * @param  Builder<Service>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
