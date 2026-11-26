<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('an administrator can be provisioned from the console', function () {
    $this->artisan('staff:create', ['--name' => 'Ada Admin', '--email' => 'Ada@Example.test', '--role' => ['admin']])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'a-strong-passphrase')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.test')->sole();

    expect($user->hasRole(Role::Admin->value))->toBeTrue()
        ->and($user->isActive())->toBeTrue()
        ->and(Hash::check('a-strong-passphrase', $user->password))->toBeTrue();
});

test('invalid roles are rejected and no user is created', function () {
    $this->artisan('staff:create', ['--name' => 'Eve', '--email' => 'eve@example.test', '--role' => ['superuser']])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'a-strong-passphrase')
        ->assertFailed();

    expect(User::query()->where('email', 'eve@example.test')->exists())->toBeFalse();
});

test('mismatched password confirmation is rejected', function () {
    $this->artisan('staff:create', ['--name' => 'Bob', '--email' => 'bob@example.test', '--role' => ['nurse']])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'something-else')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('duplicate emails are rejected regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('staff:create', ['--name' => 'Dup', '--email' => 'TAKEN@example.test', '--role' => ['doctor']])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'a-strong-passphrase')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
