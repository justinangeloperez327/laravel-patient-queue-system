<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Services\ProviderAvailability;
use App\Support\ClinicTime;
use App\Support\ConstraintViolation;
use App\Support\DailyNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Books a free slot. The requested start must be one of the provider's
 * available slots; overlapping bookings that race past the check are
 * rejected by PostgreSQL exclusion constraints.
 */
final class BookAppointment
{
    use ValidatesBookings;

    public function __construct(private readonly ProviderAvailability $availability) {}

    protected function availability(): ProviderAvailability
    {
        return $this->availability;
    }

    /**
     * @param  array<string, mixed>  $input  patient_id, service_id, provider_id, scheduled_start_at (ISO-8601), reason_for_visit?
     */
    public function handle(User $actor, array $input): Appointment
    {
        Gate::forUser($actor)->authorize('create', Appointment::class);

        /** @var array{patient_id: int, service_id: int, provider_id: int, reason_for_visit?: string|null} $data */
        $data = Validator::make($input, [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'provider_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'scheduled_start_at' => ['required', 'string'],
            'reason_for_visit' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $patient = Patient::query()->findOrFail((int) $data['patient_id']);
        $service = Service::query()->findOrFail((int) $data['service_id']);
        $provider = User::query()->findOrFail((int) $data['provider_id']);
        $start = $this->parseStart($input['scheduled_start_at']);
        $reason = isset($data['reason_for_visit']) ? trim($data['reason_for_visit']) : null;

        return ConstraintViolation::guard(fn (): Appointment => DB::transaction(function () use ($actor, $patient, $service, $provider, $start, $reason): Appointment {
            $this->ensureBookable($patient, $service, $provider);
            $this->ensureWithinHorizon($start);
            $slot = $this->requireSlot($provider, $service, $start);
            $this->ensurePatientFree($patient, $slot);

            $appointment = new Appointment(['reason_for_visit' => $reason === '' ? null : $reason]);
            $appointment->appointment_number = DailyNumber::next('APT', ClinicTime::today(), 'appointments');
            $appointment->patient_id = $patient->id;
            $appointment->service_id = $service->id;
            $appointment->provider_id = $provider->id;
            $appointment->scheduled_start_at = $slot->start;
            $appointment->scheduled_end_at = $slot->end;
            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->booked_by = $actor->id;
            $appointment->save();

            AuditLog::record($actor, 'appointment.booked', $appointment, [
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'scheduled_start_at' => $slot->start->toIso8601String(),
            ]);

            return $appointment;
        }), $this->bookingConstraints());
    }
}
