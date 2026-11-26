<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Counter;
use App\Models\User;

/**
 * Counters are managed by administrators only.
 */
final class CounterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageCounters->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageCounters->value);
    }

    public function update(User $user, Counter $counter): bool
    {
        return $user->can(Permission::ManageCounters->value);
    }
}
