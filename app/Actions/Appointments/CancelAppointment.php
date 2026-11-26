<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a pending appointment and frees its slot.
 */
final class CancelAppointment
{
    use TransitionsAppointments;

    public function handle(User $actor, Appointment $appointment, string $reason): Appointment
    {
        Gate::forUser($actor)->authorize('update', $appointment);

        /** @var array{cancellation_reason: string} $data */
        $data = Validator::make(['cancellation_reason' => trim($reason)], [
            'cancellation_reason' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $appointment, $data): Appointment {
            $locked = $this->lockForTransition($appointment, AppointmentStatus::Cancelled);

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'status' => __('Checked-in appointments are cancelled by cancelling the visit.'),
                ]);
            }

            $locked->status = AppointmentStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $data['cancellation_reason'];
            $locked->save();

            AuditLog::record($actor, 'appointment.cancelled', $locked);

            return $locked;
        });
    }
}
