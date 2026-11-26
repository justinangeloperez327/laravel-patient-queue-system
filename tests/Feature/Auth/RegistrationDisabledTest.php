<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

test('public staff registration is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse()
        ->and(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

test('the login page does not link to registration', function () {
    $this->get(route('login'))->assertDontSee('Sign up');
});
