<?php

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\ConfirmAppointment;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Visits\CheckInAppointment;
use App\Enums\AppointmentStatus;
use App\Livewire\Appointments\AppointmentDetail;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the status transition table matches the documented lifecycle', function () {
    expect(AppointmentStatus::Scheduled->allowedTransitions())->toBe([AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::Cancelled, AppointmentStatus::NoShow])
        ->and(AppointmentStatus::Confirmed->allowedTransitions())->toBe([AppointmentStatus::CheckedIn, AppointmentStatus::Cancelled, AppointmentStatus::NoShow])
        ->and(AppointmentStatus::CheckedIn->allowedTransitions())->toBe([AppointmentStatus::Completed, AppointmentStatus::Cancelled])
        ->and(AppointmentStatus::Completed->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::NoShow->allowedTransitions())->toBe([]);
});

test('a scheduled appointment can be confirmed once', function () {
    $fixture = bookingFixture();
    $appointment = app(ConfirmAppointment::class)->handle($fixture['receptionist'], book($fixture));

    expect($appointment->status)->toBe(AppointmentStatus::Confirmed);
    expectValidationError(fn () => app(ConfirmAppointment::class)->handle($fixture['receptionist'], $appointment), 'status');
});

test('cancelling needs a reason, frees the slot and is final', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    expectValidationError(fn () => app(CancelAppointment::class)->handle($fixture['receptionist'], $appointment, '  '), 'cancellation_reason');

    $cancelled = app(CancelAppointment::class)->handle($fixture['receptionist'], $appointment, 'Patient requested');

    expect($cancelled->status)->toBe(AppointmentStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Patient requested')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and(book($fixture, overrides: ['patient_id' => Patient::factory()->create()->id])->exists)->toBeTrue();

    expectValidationError(fn () => app(ConfirmAppointment::class)->handle($fixture['receptionist'], $cancelled), 'status');
    expectValidationError(fn () => app(CancelAppointment::class)->handle($fixture['receptionist'], $cancelled, 'Again'), 'status');
});

test('rescheduling moves a pending appointment, keeps its number and is audited', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $other = providerIn($fixture['service']->department);
    ProviderSchedule::factory()->create(['provider_id' => $other->id, 'service_id' => $fixture['service']->id, 'start_time' => '10:00', 'end_time' => '11:00']);

    $moved = app(RescheduleAppointment::class)->handle($fixture['receptionist'], $appointment, [
        'provider_id' => $other->id, 'scheduled_start_at' => '2030-01-07T10:30:00+00:00',
    ]);

    expect($moved->appointment_number)->toBe($appointment->appointment_number)
        ->and($moved->provider_id)->toBe($other->id)
        ->and($moved->scheduled_start_at->utc()->format('H:i'))->toBe('10:30');

    $log = AuditLog::query()->where('action', 'appointment.rescheduled')->sole();
    expect($log->metadata['from'])->toBe(['provider_id' => $fixture['doctor']->id, 'scheduled_start_at' => '2030-01-07T09:00:00+00:00'])
        ->and($log->metadata['to'])->toBe(['provider_id' => $other->id, 'scheduled_start_at' => '2030-01-07T10:30:00+00:00']);
});

test('rescheduling into the appointment\'s own overlapping slot is allowed', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    $moved = app(RescheduleAppointment::class)->handle($fixture['receptionist'], $appointment, [
        'provider_id' => $fixture['doctor']->id, 'scheduled_start_at' => '2030-01-07T09:00:00+00:00',
    ]);

    expect($moved->scheduled_start_at->utc()->format('H:i'))->toBe('09:00');
});

test('only pending appointments can be rescheduled', function () {
    $fixture = bookingFixture();
    $appointment = app(CancelAppointment::class)->handle($fixture['receptionist'], book($fixture), 'x');

    expectValidationError(fn () => app(RescheduleAppointment::class)->handle($fixture['receptionist'], $appointment, [
        'provider_id' => $fixture['doctor']->id, 'scheduled_start_at' => '2030-01-07T10:00:00+00:00',
    ]), 'status');
});

test('a no-show can only be recorded after the late grace period', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture); // 09:00, grace 15 minutes

    $this->travelTo(CarbonImmutable::parse('2030-01-07 09:15:00', 'UTC'));
    expectValidationError(fn () => app(MarkAppointmentNoShow::class)->handle($fixture['receptionist'], $appointment), 'status');

    $this->travelTo(CarbonImmutable::parse('2030-01-07 09:15:01', 'UTC'));
    expect(app(MarkAppointmentNoShow::class)->handle($fixture['receptionist'], $appointment)->status)->toBe(AppointmentStatus::NoShow);
});

test('the grace period is configurable', function () {
    config(['clinic.appointments.late_grace_minutes' => 30]);
    $fixture = bookingFixture();
    $appointment = book($fixture);

    $this->travelTo(CarbonImmutable::parse('2030-01-07 09:20:00', 'UTC'));
    expectValidationError(fn () => app(MarkAppointmentNoShow::class)->handle($fixture['receptionist'], $appointment), 'status');
    expect(app(CheckInAppointment::class)->handle($fixture['receptionist'], $appointment)->exists)->toBeTrue();
});

test('the daily sweep marks only pending appointments from earlier days', function () {
    $fixture = bookingFixture();
    $pending = book($fixture);
    $checkedIn = book($fixture, '2030-01-07 09:15:00', ['patient_id' => Patient::factory()->create()->id]);
    $this->travelTo(CarbonImmutable::parse('2030-01-07 09:10:00', 'UTC'));
    app(CheckInAppointment::class)->handle($fixture['receptionist'], $checkedIn);

    $this->travelTo(CarbonImmutable::parse('2030-01-07 23:00:00', 'UTC'));
    $this->artisan('appointments:mark-no-shows')->assertSuccessful();
    expect($pending->refresh()->status)->toBe(AppointmentStatus::Scheduled); // same day: left to reception

    $this->travelTo(CarbonImmutable::parse('2030-01-08 00:30:00', 'UTC'));
    $this->artisan('appointments:mark-no-shows')->expectsOutputToContain('Marked 1')->assertSuccessful();

    expect($pending->refresh()->status)->toBe(AppointmentStatus::NoShow)
        ->and($checkedIn->refresh()->status)->toBe(AppointmentStatus::CheckedIn)
        ->and(AuditLog::query()->where('action', 'appointment.no_show')->sole()->actor_id)->toBeNull();
});

test('the detail page drives confirm, reschedule and cancel', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);

    Livewire::actingAs($fixture['receptionist'])->test(AppointmentDetail::class, ['appointment' => $appointment])
        ->call('confirm')->assertHasNoErrors()
        ->call('startRescheduling')
        ->assertSet('provider_id', (string) $fixture['doctor']->id)
        ->set('scheduled_start_at', '2030-01-07T11:00:00+00:00')
        ->call('reschedule')->assertHasNoErrors()
        ->assertSee('Rescheduled')
        ->set('cancellation_reason', 'Patient requested')
        ->call('cancel')->assertHasNoErrors();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and(AuditLog::query()->where('subject_id', $appointment->id)->pluck('action')->all())
        ->toBe(['appointment.booked', 'appointment.confirmed', 'appointment.rescheduled', 'appointment.cancelled']);
});

test('audit logs are append-only', function () {
    $fixture = bookingFixture();
    book($fixture);
    $log = AuditLog::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditLog::query()->sole()->action)->toBe('appointment.booked');
});
