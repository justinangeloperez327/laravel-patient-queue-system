<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderScheduleException;
use App\Models\User;

/**
 * Provider unavailability is managed by administrators. Only exceptions that have not started may be removed.
 */
final class ProviderScheduleExceptionPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function delete(User $user, ProviderScheduleException $exception): bool
    {
        return $user->can(Permission::ManageSchedules->value) && $exception->starts_at->isFuture();
    }
}
