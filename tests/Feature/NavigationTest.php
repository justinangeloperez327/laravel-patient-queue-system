<?php

use App\Enums\Role;

test('the sidebar only links to permitted areas', function (Role $role, array $visible, array $hidden) {
    $response = $this->actingAs(staffWithRole($role))->get(route('dashboard'))->assertOk();

    foreach ($visible as $route) {
        $response->assertSee(route($route), false);
    }

    foreach ($hidden as $route) {
        $response->assertDontSee(route($route), false);
    }
})->with([
    'admin' => [Role::Admin, ['departments.index', 'services.index', 'counters.index', 'staff.index'], ['patients.index']],
    'receptionist' => [Role::Receptionist, ['patients.index'], ['departments.index', 'services.index', 'counters.index', 'staff.index']],
    'doctor' => [Role::Doctor, [], ['patients.index', 'departments.index', 'staff.index']],
]);
