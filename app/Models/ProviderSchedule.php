<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use Database\Factories\ProviderScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recurring weekly availability of a provider for a service. Times are
 * clinic-local wall-clock times (HH:MM:SS).
 *
 * @property int $id
 * @property int $provider_id
 * @property int $service_id
 * @property DayOfWeek $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property int $slot_duration_minutes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $provider
 * @property-read Service $service
 */
#[Fillable(['provider_id', 'service_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration_minutes', 'is_active'])]
class ProviderSchedule extends Model
{
    /** @use HasFactory<ProviderScheduleFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'slot_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function timeRangeLabel(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }
}
