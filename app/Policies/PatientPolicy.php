<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;

/**
 * Patient demographics are receptionist-only in this phase (docs/ROLES_AND_PERMISSIONS.md). Patients are never deleted; they are deactivated.
 */
final class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewPatients->value);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can(Permission::ViewPatients->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RegisterPatients->value);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can(Permission::UpdatePatientDemographics->value);
    }
}
