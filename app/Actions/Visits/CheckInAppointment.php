<?php

declare(strict_types=1);

namespace App\Actions\Visits;

use App\Enums\AppointmentStatus;
use App\Enums\SchedulingPreference;
use App\Enums\VisitType;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Visit;
use App\Services\Queue\TicketIssuer;
use App\Services\Queue\TriageRouting;
use App\Support\ClinicTime;
use App\Support\ConstraintViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Checks in a booked patient: creates the visit and marks the appointment
 * checked in. Allowed on the appointment's clinic-local date until the
 * start time plus the late grace period.
 */
final class CheckInAppointment
{
    use OpensVisits;

    public function __construct(
        private readonly TicketIssuer $issuer,
        private readonly TriageRouting $routing,
    ) {}

    /**
     * Opens the visit and issues its first ticket in one transaction. The
     * ticket becomes eligible at the appointment time minus the early
     * eligibility window (or now, if later).
     */
    public function handle(User $actor, Appointment $appointment, bool $sendToTriage = false): Visit
    {
        Gate::forUser($actor)->authorize('checkIn', $appointment);

        return ConstraintViolation::guard(fn (): Visit => DB::transaction(function () use ($actor, $appointment, $sendToTriage): Visit {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AppointmentStatus::CheckedIn) {
                throw ValidationException::withMessages(['appointment' => __('This appointment is already checked in.')]);
            }

            if (! $locked->status->canTransitionTo(AppointmentStatus::CheckedIn)) {
                throw ValidationException::withMessages(['appointment' => __('A :status appointment cannot be checked in.', ['status' => strtolower($locked->status->label())])]);
            }

            if (! $locked->localDate()->equalTo(ClinicTime::today())) {
                throw ValidationException::withMessages(['appointment' => __('Appointments can only be checked in on their scheduled day.')]);
            }

            if (now()->gt($locked->checkInDeadline())) {
                throw ValidationException::withMessages([
                    'appointment' => __('The :minutes-minute grace period has passed. Record a no-show, then register the patient as a walk-in if they are to be seen.', [
                        'minutes' => Appointment::lateGraceMinutes(),
                    ]),
                ]);
            }

            $this->ensurePatientCanCheckIn($locked->patient);
            $this->ensureServiceOpen($locked->service);

            $visit = $this->openVisit($actor, $locked->patient, $locked->service, VisitType::Appointment, $locked->id, $locked->reason_for_visit);

            $locked->status = AppointmentStatus::CheckedIn;
            $locked->checked_in_at = $visit->checked_in_at;
            $locked->save();

            $first = $this->routing->firstService($locked->service, $sendToTriage);
            $early = config('clinic.appointments.early_eligibility_minutes');

            $this->issuer->issue(
                actor: $actor,
                visit: $visit,
                service: $first,
                preference: SchedulingPreference::Appointment,
                eligibleAt: CarbonImmutable::instance($locked->scheduled_start_at)->subMinutes(is_int($early) ? $early : 15),
                // The booked provider takes the patient; a triage ticket is open to any triage nurse.
                assignedProviderId: $first->id === $locked->service_id ? $locked->provider_id : null,
            );

            AuditLog::record($actor, 'visit.checked_in', $visit, ['appointment_id' => $locked->id, 'late' => now()->gt($locked->scheduled_start_at)]);

            return $visit;
        }), $this->checkInConstraints());
    }
}
