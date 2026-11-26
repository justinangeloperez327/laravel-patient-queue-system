<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Validation\ValidationException;

trait TransitionsAppointments
{
    protected function lockForTransition(Appointment $appointment, AppointmentStatus $next): Appointment
    {
        /** @var Appointment $locked */
        $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => __('A :from appointment cannot become :to.', [
                    'from' => strtolower($locked->status->label()),
                    'to' => strtolower($next->label()),
                ]),
            ]);
        }

        return $locked;
    }
}
