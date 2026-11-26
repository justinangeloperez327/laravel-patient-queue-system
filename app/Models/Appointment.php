<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A receptionist-booked appointment. Times are UTC instants.
 *
 * @property int $id
 * @property string $appointment_number
 * @property int $patient_id
 * @property int $service_id
 * @property int $provider_id
 * @property CarbonImmutable $scheduled_start_at
 * @property CarbonImmutable $scheduled_end_at
 * @property string|null $reason_for_visit
 * @property AppointmentStatus $status
 * @property int $booked_by
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Patient $patient
 * @property-read Service $service
 * @property-read User $provider
 * @property-read User $bookedBy
 * @property-read Visit|null $visit
 */
#[Fillable(['reason_for_visit'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /**
     * @return HasOne<Visit, $this>
     */
    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    /**
     * The clinic-local date the appointment takes place on.
     */
    public function localDate(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->scheduled_start_at)->setTimezone(ClinicTime::timezone())->startOfDay();
    }

    /**
     * Latest instant at which the patient may still check in.
     */
    public function checkInDeadline(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->scheduled_start_at)->addMinutes(self::lateGraceMinutes());
    }

    public static function lateGraceMinutes(): int
    {
        $minutes = config('clinic.appointments.late_grace_minutes');

        return is_int($minutes) ? $minutes : 15;
    }

    /**
     * Appointments starting within a clinic-local day.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOnLocalDate(Builder $query, CarbonImmutable $localDate): void
    {
        $start = $localDate->setTimezone(ClinicTime::timezone())->startOfDay();

        $query->where('scheduled_start_at', '>=', $start->utc())
            ->where('scheduled_start_at', '<', $start->addDay()->utc());
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value]);
    }
}
