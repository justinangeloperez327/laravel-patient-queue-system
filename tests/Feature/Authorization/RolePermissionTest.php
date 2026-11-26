<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

test('every role and permission in the catalogue is stored', function () {
    expect(RoleModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::values())->sort()->values()->all())
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

test('syncing is idempotent and removes permissions dropped from the catalogue', function () {
    PermissionModel::findOrCreate('legacy.permission', 'web');

    app(SyncRolesAndPermissions::class)->handle();
    app(SyncRolesAndPermissions::class)->handle();

    expect(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeFalse()
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()))
        ->and(RoleModel::query()->count())->toBe(count(Role::cases()));
});

test('each role is granted exactly its catalogue permissions', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))
            ->toBe(in_array($permission, $role->permissions(), true), "{$role->value} / {$permission->value}");
    }
})->with(Role::cases());

test('administrators have no implicit clinical or reception permissions', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    foreach ([
        Permission::RegisterPatients,
        Permission::ManageAppointments,
        Permission::IssueTickets,
        Permission::ViewClinicalHistory,
        Permission::WriteSoapNotes,
        Permission::SignClinicalNotes,
        Permission::OperateQueue,
    ] as $permission) {
        expect($admin->can($permission->value))->toBeFalse($permission->value);
    }
});

test('receptionists and service staff have no clinical permissions', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $clinical = array_filter(
        Permission::cases(),
        static fn (Permission $permission): bool => str_starts_with($permission->value, 'clinical.'),
    );

    foreach ($clinical as $permission) {
        expect($user->can($permission->value))->toBeFalse($permission->value);
    }
})->with([Role::Receptionist, Role::ServiceStaff]);

test('a user may hold multiple roles and receives the union of permissions', function () {
    $user = User::factory()->withRole(Role::Nurse, Role::Receptionist)->create();

    expect($user->can(Permission::PerformTriage->value))->toBeTrue()
        ->and($user->can(Permission::RegisterPatients->value))->toBeTrue()
        ->and($user->can(Permission::SignClinicalNotes->value))->toBeFalse();
});

test('unknown abilities are denied by default', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    expect($user->can('anything.undefined'))->toBeFalse();
});
