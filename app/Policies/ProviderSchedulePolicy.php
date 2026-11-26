<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderSchedule;
use App\Models\User;

/**
 * Provider availability is managed by administrators.
 */
final class ProviderSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function update(User $user, ProviderSchedule $schedule): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }
}
