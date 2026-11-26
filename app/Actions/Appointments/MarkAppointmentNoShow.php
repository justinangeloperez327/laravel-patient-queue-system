<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ClinicTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Marks a pending appointment as a no-show once the late grace period after
 * its start has passed. A null actor means the scheduled system sweep.
 */
final class MarkAppointmentNoShow
{
    use TransitionsAppointments;

    public function handle(?User $actor, Appointment $appointment): Appointment
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $appointment);
        }

        return DB::transaction(function () use ($actor, $appointment): Appointment {
            $locked = $this->lockForTransition($appointment, AppointmentStatus::NoShow);

            if (now()->lte($locked->checkInDeadline())) {
                throw ValidationException::withMessages([
                    'status' => __('A no-show can be recorded after :time (start plus :minutes minutes of grace).', [
                        'time' => ClinicTime::toLocalDisplay($locked->checkInDeadline()),
                        'minutes' => Appointment::lateGraceMinutes(),
                    ]),
                ]);
            }

            $locked->status = AppointmentStatus::NoShow;
            $locked->save();

            AuditLog::record($actor, 'appointment.no_show', $locked, ['automatic' => $actor === null]);

            return $locked;
        });
    }
}
