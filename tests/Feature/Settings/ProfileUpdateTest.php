<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.test')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.test')
        ->and($user->email_verified_at)->toBeNull();
});

test('email is stored in lowercase so the user can still log in', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'Test@Example.test')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('test@example.test');

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => 'Test@Example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('email must be unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'Taken@Example.test')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('staff can not delete their own account', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');

    expect(fn () => Livewire::test('pages::settings.delete-user-modal'))->toThrow(Exception::class);
});
