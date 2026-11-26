<?php

declare(strict_types=1);

namespace App\Actions\Visits;

use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\ClinicTime;
use App\Support\DailyNumber;
use Illuminate\Validation\ValidationException;

/**
 * Shared visit creation. The partial unique index visits_one_open_per_patient
 * guarantees one open visit per patient even when check-ins race.
 */
trait OpensVisits
{
    protected function ensurePatientCanCheckIn(Patient $patient): void
    {
        if (! $patient->is_active) {
            throw ValidationException::withMessages(['patient_id' => __('This patient record is inactive.')]);
        }

        if ($patient->visits()->whereIn('status', [VisitStatus::CheckedIn->value, VisitStatus::InProgress->value])->exists()) {
            throw ValidationException::withMessages(['patient_id' => __('This patient is already checked in.')]);
        }
    }

    protected function ensureServiceOpen(Service $service): void
    {
        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => __('This service is not active.')]);
        }
    }

    protected function openVisit(User $actor, Patient $patient, Service $service, VisitType $type, ?int $appointmentId, ?string $reason): Visit
    {
        $visit = new Visit;
        $visit->visit_number = DailyNumber::next('V', ClinicTime::today(), 'visits');
        $visit->patient_id = $patient->id;
        $visit->appointment_id = $appointmentId;
        $visit->requested_service_id = $service->id;
        $visit->visit_type = $type;
        $visit->status = VisitStatus::CheckedIn;
        $visit->reason_for_visit = $reason === null || trim($reason) === '' ? null : trim($reason);
        $visit->registered_by = $actor->id;
        $visit->checked_in_at = now();
        $visit->save();

        return $visit;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function checkInConstraints(): array
    {
        return [
            'queue_tickets_one_unresolved_per_visit_service' => ['patient_id', __('This patient already has a ticket in the queue.')],
            'visits_one_open_per_patient' => ['patient_id', __('This patient is already checked in.')],
            'visits_appointment_id_unique' => ['appointment', __('This appointment is already checked in.')],
        ];
    }
}
