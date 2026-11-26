<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Service;
use App\Models\User;

/**
 * Services are managed by administrators only.
 */
final class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageServices->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageServices->value);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can(Permission::ManageServices->value);
    }
}
