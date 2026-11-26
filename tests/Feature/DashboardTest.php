<?php

use App\Enums\Role;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the root url sends staff to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

test('staff with any role can visit the dashboard', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($role->label());
})->with(Role::cases());

test('users without a role are denied the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertForbidden();
});
