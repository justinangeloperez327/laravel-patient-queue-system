<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotently aligns stored roles and permissions with the enum catalogue.
 * Permissions no longer in the catalogue are removed.
 */
final class SyncRolesAndPermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(string $guard = 'web'): void
    {
        $this->registrar->forgetCachedPermissions();

        DB::transaction(function () use ($guard): void {
            foreach (PermissionEnum::cases() as $permission) {
                Permission::findOrCreate($permission->value, $guard);
            }

            Permission::query()
                ->where('guard_name', $guard)
                ->whereNotIn('name', PermissionEnum::values())
                ->delete();

            foreach (RoleEnum::cases() as $role) {
                Role::findOrCreate($role->value, $guard)->syncPermissions(
                    array_map(static fn (PermissionEnum $permission): string => $permission->value, $role->permissions()),
                );
            }
        });

        $this->registrar->forgetCachedPermissions();
    }
}
