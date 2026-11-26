<?php

use App\Enums\Permission;
use App\Enums\Role;

test('every role can view the dashboard', function (Role $role) {
    expect($role->permissions())->toContain(Permission::ViewDashboard);
})->with(Role::cases());

test('role permissions contain no duplicates', function (Role $role) {
    $values = array_map(fn (Permission $permission) => $permission->value, $role->permissions());

    expect($values)->toBe(array_values(array_unique($values)));
})->with(Role::cases());

test('every permission is granted to at least one role', function () {
    $granted = collect(Role::cases())
        ->flatMap(fn (Role $role) => $role->permissions())
        ->unique()
        ->count();

    expect($granted)->toBe(count(Permission::cases()));
});

test('only doctors may sign clinical notes', function () {
    $signers = array_filter(Role::cases(), fn (Role $role) => in_array(Permission::SignClinicalNotes, $role->permissions(), true));

    expect(array_values($signers))->toBe([Role::Doctor]);
});
