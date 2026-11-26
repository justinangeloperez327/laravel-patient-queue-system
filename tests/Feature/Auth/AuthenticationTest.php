<?php

use App\Enums\Role;
use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $this->get(route('login'))->assertOk();
});

test('staff can authenticate using the login screen', function () {
    $user = User::factory()->withRole(Role::Receptionist)->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('email is matched case-insensitively at login', function () {
    $user = User::factory()->create(['email' => 'nurse@example.test']);

    $this->post(route('login.store'), [
        'email' => 'Nurse@Example.TEST',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('users can not authenticate with an invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('inactive users can not authenticate and get the generic failure message', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('a user deactivated mid-session is signed out on the next request', function () {
    $user = User::factory()->withRole(Role::Doctor)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('login attempts are rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(429);

    $this->assertGuest();
});

test('users with two factor enabled are redirected to the two factor challenge', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('inactive users with two factor enabled are not sent to the challenge', function () {
    $user = User::factory()->withTwoFactor()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
});
