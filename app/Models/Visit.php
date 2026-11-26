<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VisitStatus;
use App\Enums\VisitType;
use Carbon\CarbonImmutable;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check-in of a patient. All tickets for that attendance (phase 04)
 * belong to the same visit.
 *
 * @property int $id
 * @property string $visit_number
 * @property int $patient_id
 * @property int|null $appointment_id
 * @property int $requested_service_id
 * @property VisitType $visit_type
 * @property VisitStatus $status
 * @property string|null $reason_for_visit
 * @property int $registered_by
 * @property CarbonImmutable $checked_in_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Patient $patient
 * @property-read Appointment|null $appointment
 * @property-read Service $requestedService
 * @property-read User $registeredBy
 */
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    /**
     * Every attribute is set explicitly by the reception actions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'checked_in',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visit_type' => VisitType::class,
            'status' => VisitStatus::class,
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function requestedService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'requested_service_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
