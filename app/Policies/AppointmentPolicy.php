<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\User;

/**
 * Booking is receptionist-only (docs/ROLES_AND_PERMISSIONS.md).
 */
final class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageAppointments->value);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can(Permission::ManageAppointments->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageAppointments->value);
    }

    /**
     * Reschedule, confirm, cancel or record a no-show.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->can(Permission::ManageAppointments->value);
    }

    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $user->can(Permission::CheckInVisits->value);
    }
}
