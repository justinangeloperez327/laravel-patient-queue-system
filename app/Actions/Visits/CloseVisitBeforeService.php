<?php

declare(strict_types=1);

namespace App\Actions\Visits;

use App\Enums\AppointmentStatus;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Closes a visit at reception before service starts, either as cancelled
 * (e.g. registered in error) or as left without service. Once the queue
 * engine starts service (phase 04) the visit is closed from the queue.
 *
 * Cancelling an appointment visit also cancels the appointment; a patient
 * who left keeps the appointment as checked in (they attended).
 */
final class CloseVisitBeforeService
{
    public function handle(User $actor, Visit $visit, VisitStatus $outcome, string $reason): Visit
    {
        Gate::forUser($actor)->authorize('update', $visit);

        if (! in_array($outcome, [VisitStatus::Cancelled, VisitStatus::LeftWithoutService], true)) {
            throw new \InvalidArgumentException('Outcome must be cancelled or left without service.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($actor, $visit, $outcome, $data): Visit {
            /** @var Visit $locked */
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VisitStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'status' => __('Only visits that have not started service can be closed at reception.'),
                ]);
            }

            $locked->status = $outcome;
            $locked->cancelled_at = now();
            $locked->save();

            if ($outcome === VisitStatus::Cancelled && $locked->appointment_id !== null) {
                /** @var Appointment $appointment */
                $appointment = Appointment::query()->whereKey($locked->appointment_id)->lockForUpdate()->firstOrFail();
                $appointment->status = AppointmentStatus::Cancelled;
                $appointment->cancelled_at = now();
                $appointment->cancellation_reason = __('Visit cancelled: :reason', ['reason' => $data['reason']]);
                $appointment->save();
            }

            AuditLog::record($actor, $outcome === VisitStatus::Cancelled ? 'visit.cancelled' : 'visit.left_without_service', $locked, [
                'appointment_id' => $locked->appointment_id,
            ]);

            return $locked;
        });
    }
}
