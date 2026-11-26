<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\ClinicalAccess;

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

    /**
     * Clinical history: clinicians with an active care relationship only.
     * Reads must still be audited through ClinicalAccess::authorizeHistory().
     */
    public function viewClinicalHistory(User $user, Patient $patient): bool
    {
        return app(ClinicalAccess::class)->hasCareRelationship($user, $patient);
    }

    /**
     * The patient record page: reception (demographics) or clinicians in a care relationship.
     */
    public function viewRecord(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient) || $this->viewClinicalHistory($user, $patient);
    }
}
