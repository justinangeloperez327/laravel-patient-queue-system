<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Staff accounts are managed by administrators. Accounts are never deleted; they are deactivated.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function update(User $user, User $staff): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }
}
