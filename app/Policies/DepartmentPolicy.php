<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;

/**
 * Departments are managed by administrators only.
 */
final class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageDepartments->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageDepartments->value);
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can(Permission::ManageDepartments->value);
    }
}
