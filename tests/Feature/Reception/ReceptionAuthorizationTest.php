<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\CancelAppointment;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;

test('only receptionists reach appointment, reception and visit screens', function (Role $role) {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $user = staffWithRole($role);

    foreach ([route('appointments.index'), route('appointments.create'), route('appointments.show', $appointment), route('reception'), route('visits.index')] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
})->with([Role::Admin, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('receptionists reach appointment, reception and visit screens', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    foreach ([route('appointments.index', ['date' => '2030-01-07']), route('appointments.create'), route('appointments.show', $appointment), route('reception'), route('visits.index')] as $url) {
        $this->actingAs($fixture['receptionist'])->get($url)->assertOk();
    }

    $this->actingAs($fixture['receptionist'])->get(route('appointments.index', ['date' => '2030-01-07']))->assertSee($appointment->appointment_number);
});

test('reception actions authorize the acting user themselves', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $doctor = $fixture['doctor'];

    expect(fn () => app(BookAppointment::class)->handle($doctor, []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelAppointment::class)->handle($doctor, $appointment, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CheckInAppointment::class)->handle($doctor, $appointment))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegisterWalkIn::class)->handle($doctor, []))->toThrow(AuthorizationException::class);
});

test('the receptionist sidebar links to reception areas', function () {
    $response = $this->actingAs(receptionist())->get(route('dashboard'));

    foreach (['reception', 'appointments.index', 'visits.index', 'patients.index'] as $route) {
        $response->assertSee(route($route), false);
    }
});
