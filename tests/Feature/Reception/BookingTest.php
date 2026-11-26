<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Livewire\Appointments\BookAppointmentForm;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a receptionist books a free slot', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture, '2030-01-07 09:30:00', ['reason_for_visit' => '  Follow-up ']);

    expect($appointment->appointment_number)->toBe('APT-20300107-0001')
        ->and($appointment->status)->toBe(AppointmentStatus::Scheduled)
        ->and($appointment->scheduled_start_at->utc()->format('Y-m-d H:i'))->toBe('2030-01-07 09:30')
        ->and($appointment->scheduled_end_at->utc()->format('H:i'))->toBe('09:45')
        ->and($appointment->booked_by)->toBe($fixture['receptionist']->id)
        ->and($appointment->reason_for_visit)->toBe('Follow-up');
});

test('appointment numbers are sequential per booking day', function () {
    $fixture = bookingFixture();
    book($fixture, '2030-01-07 09:00:00');
    $second = book($fixture, '2030-01-07 09:15:00', ['patient_id' => Patient::factory()->create()->id]);

    $this->travelTo(now()->addDay());
    ProviderSchedule::query()->update(['day_of_week' => 2]);
    $nextDay = book($fixture, '2030-01-08 09:00:00', ['patient_id' => Patient::factory()->create()->id]);

    expect($second->appointment_number)->toBe('APT-20300107-0002')
        ->and($nextDay->appointment_number)->toBe('APT-20300108-0001');
});

test('the requested time must be an available slot', function (string $start) {
    $fixture = bookingFixture();

    expectValidationError(fn () => book($fixture, $start), 'scheduled_start_at');
})->with([
    'misaligned' => ['2030-01-07 09:05:00'],
    'outside the block' => ['2030-01-07 12:00:00'],
    'no schedule that day' => ['2030-01-08 09:00:00'],
    'in the past' => ['2030-01-06 09:00:00'],
]);

test('a booked slot cannot be booked again', function () {
    $fixture = bookingFixture();
    book($fixture);

    expectValidationError(fn () => book($fixture, overrides: ['patient_id' => Patient::factory()->create()->id]), 'scheduled_start_at');
});

test('a patient cannot hold overlapping appointments with different providers', function () {
    $fixture = bookingFixture();
    book($fixture);
    $other = providerIn($fixture['service']->department);
    ProviderSchedule::factory()->create(['provider_id' => $other->id, 'service_id' => $fixture['service']->id, 'start_time' => '09:00', 'end_time' => '10:00']);

    expectValidationError(fn () => book($fixture, overrides: ['provider_id' => $other->id]), 'patient_id');
});

test('participants must be eligible', function (string $case, string $field) {
    $fixture = bookingFixture();
    $overrides = match ($case) {
        'inactive patient' => ['patient_id' => Patient::factory()->create(['is_active' => false])->id],
        'service without appointments' => ['service_id' => Service::factory()->create(['department_id' => $fixture['service']->department_id])->id],
        'unassigned provider' => ['provider_id' => staffWithRole(Role::Doctor)->id],
        'not a provider' => ['provider_id' => $fixture['receptionist']->id],
    };

    expectValidationError(fn () => book($fixture, overrides: $overrides), $field);
})->with([
    ['inactive patient', 'patient_id'],
    ['service without appointments', 'service_id'],
    ['unassigned provider', 'provider_id'],
    ['not a provider', 'provider_id'],
]);

test('bookings beyond the horizon are rejected', function () {
    $fixture = bookingFixture();
    config(['clinic.appointments.booking_horizon_days' => 6]);

    expectValidationError(fn () => book($fixture, '2030-01-14 09:00:00'), 'scheduled_start_at');
});

test('the database rejects overlapping active bookings for a provider or a patient', function () {
    $fixture = bookingFixture();
    $first = book($fixture);
    $row = fn (array $overrides) => [
        'appointment_number' => 'APT-20300107-'.random_int(1000, 9999), 'patient_id' => Patient::factory()->create()->id,
        'service_id' => $fixture['service']->id, 'provider_id' => $fixture['doctor']->id, 'booked_by' => $fixture['receptionist']->id,
        'scheduled_start_at' => '2030-01-07 09:10:00', 'scheduled_end_at' => '2030-01-07 09:25:00', 'status' => 'scheduled', ...$overrides,
    ];

    expectConstraintViolation(fn () => DB::table('appointments')->insert($row([])), 'appointments_provider_no_overlap');
    expectConstraintViolation(fn () => DB::table('appointments')->insert($row(['provider_id' => User::factory()->create()->id, 'patient_id' => $first->patient_id])), 'appointments_patient_no_overlap');

    // Back-to-back (half-open) and cancelled bookings do not conflict.
    DB::table('appointments')->insert($row(['scheduled_start_at' => '2030-01-07 09:15:00', 'scheduled_end_at' => '2030-01-07 09:30:00']));
    DB::table('appointments')->insert($row(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'x']));

    expect(Appointment::query()->count())->toBe(3);
});

test('appointment rows enforce lifecycle consistency in the database', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    expectConstraintViolation(fn () => DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'cancelled']), 'appointments_cancellation_consistent');
    expectConstraintViolation(fn () => DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'checked_in']), 'appointments_checked_in_consistent');
    expectConstraintViolation(fn () => DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'booked']), 'appointments_status_valid');
    expectConstraintViolation(fn () => DB::table('appointments')->where('id', $appointment->id)->update(['scheduled_end_at' => '2030-01-07 08:00:00']), 'appointments_end_after_start');
});

test('booking is audited without patient identifiers', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('appointment.booked')
        ->and($log->subject_id)->toBe($appointment->id)
        ->and($log->actor_id)->toBe($fixture['receptionist']->id)
        ->and(json_encode($log->metadata))->not->toContain($fixture['patient']->last_name)
        ->not->toContain($fixture['patient']->patient_number);
});

test('the booking form walks through patient, service, provider and slot', function () {
    $fixture = bookingFixture();

    Livewire::actingAs($fixture['receptionist'])->test(BookAppointmentForm::class)
        ->set('patientSearch', $fixture['patient']->patient_number)
        ->call('selectPatient', $fixture['patient']->id)
        ->set('service_id', (string) $fixture['service']->id)
        ->assertSee($fixture['doctor']->name)
        ->set('provider_id', (string) $fixture['doctor']->id)
        ->set('slotDate', '2030-01-07')
        ->assertSee('09:00–09:15')
        ->set('scheduled_start_at', '2030-01-07T09:45:00+00:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('appointments.show', Appointment::query()->sole()));
});

test('changing the service resets provider and slot', function () {
    $fixture = bookingFixture();

    Livewire::actingAs($fixture['receptionist'])->test(BookAppointmentForm::class)
        ->set('service_id', (string) $fixture['service']->id)
        ->set('provider_id', (string) $fixture['doctor']->id)
        ->set('scheduled_start_at', '2030-01-07T09:45:00+00:00')
        ->set('service_id', '')
        ->assertSet('provider_id', '')
        ->assertSet('scheduled_start_at', '');
});
