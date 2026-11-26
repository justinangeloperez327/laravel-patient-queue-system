<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Visit;

/**
 * Reception creates and closes visits before service. Clinical staff work
 * visits through the queue (phase 04).
 */
final class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CheckInVisits->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CheckInVisits->value);
    }

    public function update(User $user, Visit $visit): bool
    {
        return $user->can(Permission::CheckInVisits->value);
    }
}
