<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ConfirmAppointment
{
    use TransitionsAppointments;

    public function handle(User $actor, Appointment $appointment): Appointment
    {
        Gate::forUser($actor)->authorize('update', $appointment);

        return DB::transaction(function () use ($actor, $appointment): Appointment {
            $locked = $this->lockForTransition($appointment, AppointmentStatus::Confirmed);
            $locked->status = AppointmentStatus::Confirmed;
            $locked->save();

            AuditLog::record($actor, 'appointment.confirmed', $locked);

            return $locked;
        });
    }
}
