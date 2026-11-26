<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DisplayDevice;
use App\Models\User;

/**
 * Display devices are configured by administrators.
 */
final class DisplayDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageDisplays->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageDisplays->value);
    }

    public function update(User $user, DisplayDevice $device): bool
    {
        return $user->can(Permission::ManageDisplays->value);
    }
}
