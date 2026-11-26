<?php

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\CloseVisit;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\AppointmentStatus;
use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Livewire\Reception\ReceptionDesk;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function checkIn(array $fixture, Appointment $appointment): Visit
{
    return app(CheckInAppointment::class)->handle($fixture['receptionist'], $appointment);
}

function walkIn(array $fixture, array $overrides = []): Visit
{
    return app(RegisterWalkIn::class)->handle($fixture['receptionist'], [
        'patient_id' => $fixture['patient']->id, 'service_id' => $fixture['service']->id, ...$overrides,
    ]);
}

test('checking in an appointment opens a visit and marks the appointment', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture, overrides: ['reason_for_visit' => 'Follow-up']);

    $visit = checkIn($fixture, $appointment);
    $appointment->refresh();

    expect($visit->visit_number)->toBe('V-20300107-0001')
        ->and($visit->visit_type)->toBe(VisitType::Appointment)
        ->and($visit->appointment_id)->toBe($appointment->id)
        ->and($visit->requested_service_id)->toBe($fixture['service']->id)
        ->and($visit->reason_for_visit)->toBe('Follow-up')
        ->and($visit->status)->toBe(VisitStatus::CheckedIn)
        ->and($appointment->status)->toBe(AppointmentStatus::CheckedIn)
        ->and($appointment->checked_in_at?->equalTo($visit->checked_in_at))->toBeTrue();
});

test('check-in is allowed early on the day and late within the grace period', function (string $now, bool $late) {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    $visit = checkIn($fixture, $appointment);

    expect(AuditLog::query()->where('action', 'visit.checked_in')->sole()->metadata['late'])->toBe($late)
        ->and($visit->exists)->toBeTrue();
})->with([
    'early morning' => ['2030-01-07 07:00:00', false],
    'on time' => ['2030-01-07 09:00:00', false],
    'end of grace' => ['2030-01-07 09:15:00', true],
]);

test('check-in after the grace period is refused', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $this->travelTo(CarbonImmutable::parse('2030-01-07 09:15:01', 'UTC'));

    expectValidationError(fn () => checkIn($fixture, $appointment), 'appointment');
    expect(Visit::query()->count())->toBe(0);
});

test('check-in is only possible on the appointment day', function () {
    $fixture = bookingFixture();
    ProviderSchedule::query()->update(['day_of_week' => 2]);
    $appointment = book($fixture, '2030-01-08 09:00:00');

    expectValidationError(fn () => checkIn($fixture, $appointment), 'appointment');
});

test('the operational day follows the clinic timezone', function () {
    config(['clinic.timezone' => 'Asia/Manila']);
    $fixture = bookingFixture();
    // 2030-01-07 08:00 Manila = 00:00 UTC; Monday block 09:00–12:00 Manila = 01:00–04:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2030-01-07 00:00:00', 'UTC'));
    $appointment = book($fixture, '2030-01-07 01:00:00');
    $this->travelTo(CarbonImmutable::parse('2030-01-06 23:30:00', 'UTC')); // still Monday 07:30 in Manila

    expect(checkIn($fixture, $appointment)->visit_number)->toBe('V-20300107-0001');
});

test('an appointment cannot be checked in twice or after cancellation', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    checkIn($fixture, $appointment);

    expectValidationError(fn () => checkIn($fixture, $appointment->refresh()), 'appointment');

    $other = book($fixture, '2030-01-07 10:00:00', ['patient_id' => Patient::factory()->create()->id]);
    app(CancelAppointment::class)->handle($fixture['receptionist'], $other, 'x');
    expectValidationError(fn () => checkIn($fixture, $other), 'appointment');
});

test('a patient with an open visit cannot be checked in again', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    walkIn($fixture);

    expectValidationError(fn () => checkIn($fixture, $appointment), 'patient_id');
    expectValidationError(fn () => walkIn($fixture), 'patient_id');
    expect(Visit::query()->count())->toBe(1);
});

test('walk-ins need an active patient and service', function () {
    $fixture = bookingFixture();

    expectValidationError(fn () => walkIn($fixture, ['patient_id' => Patient::factory()->create(['is_active' => false])->id]), 'patient_id');
    expectValidationError(fn () => walkIn($fixture, ['service_id' => Service::factory()->inactive()->create()->id]), 'service_id');
});

test('walk-in visit numbers share the daily sequence', function () {
    $fixture = bookingFixture();
    $first = walkIn($fixture);
    $second = walkIn($fixture, ['patient_id' => Patient::factory()->create()->id]);

    expect([$first->visit_number, $second->visit_number])->toBe(['V-20300107-0001', 'V-20300107-0002'])
        ->and($first->visit_type)->toBe(VisitType::WalkIn)
        ->and($first->appointment_id)->toBeNull();
});

test('cancelling an appointment visit cancels the appointment; leaving keeps it checked in', function () {
    $fixture = bookingFixture();
    $close = app(CloseVisit::class);

    $visit = checkIn($fixture, $appointment = book($fixture));
    $close->handle($fixture['receptionist'], $visit, VisitStatus::Cancelled, 'Registered in error');

    expect($visit->refresh()->status)->toBe(VisitStatus::Cancelled)
        ->and($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointment->cancellation_reason)->toBe('Visit cancelled: Registered in error');

    $other = book($fixture, '2030-01-07 10:00:00', ['patient_id' => ($patient = Patient::factory()->create())->id]);
    $otherVisit = checkIn($fixture, $other);
    $close->handle($fixture['receptionist'], $otherVisit, VisitStatus::LeftWithoutService, 'Could not wait');

    expect($otherVisit->refresh()->status)->toBe(VisitStatus::LeftWithoutService)
        ->and($other->refresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

test('a closed visit lets the patient check in again', function () {
    $fixture = bookingFixture();
    $visit = walkIn($fixture);
    app(CloseVisit::class)->handle($fixture['receptionist'], $visit, VisitStatus::LeftWithoutService, 'Left');

    expect(walkIn($fixture)->visit_number)->toBe('V-20300107-0002');
});

test('only visits that have not started service can be closed at reception', function () {
    $fixture = bookingFixture();
    $visit = walkIn($fixture);
    $visit->forceFill(['status' => VisitStatus::InProgress])->save();

    expectValidationError(fn () => app(CloseVisit::class)->handle($fixture['receptionist'], $visit, VisitStatus::Cancelled, 'x'), 'status');
    expectValidationError(fn () => app(CloseVisit::class)->handle($fixture['receptionist'], walkIn($fixture, ['patient_id' => Patient::factory()->create()->id]), VisitStatus::Cancelled, ' '), 'reason');
});

test('the database keeps visits consistent with appointments and allows one open visit per patient', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $row = fn (array $overrides) => [
        'visit_number' => 'V-20300107-'.random_int(1000, 9999), 'patient_id' => $fixture['patient']->id,
        'requested_service_id' => $fixture['service']->id, 'visit_type' => 'walk_in', 'status' => 'checked_in',
        'registered_by' => $fixture['receptionist']->id, 'checked_in_at' => now(), ...$overrides,
    ];

    expectConstraintViolation(fn () => DB::table('visits')->insert($row(['visit_type' => 'appointment', 'appointment_id' => $appointment->id, 'patient_id' => Patient::factory()->create()->id])), 'visits_appointment_id_patient_id_foreign');
    expectConstraintViolation(fn () => DB::table('visits')->insert($row(['visit_type' => 'appointment'])), 'visits_type_matches_appointment');
    expectConstraintViolation(fn () => DB::table('visits')->insert($row(['status' => 'completed'])), 'visits_completed_consistent');

    DB::table('visits')->insert($row([]));
    expectConstraintViolation(fn () => DB::table('visits')->insert($row([])), 'visits_one_open_per_patient');
    DB::table('visits')->insert($row(['status' => 'cancelled', 'cancelled_at' => now()]));

    expect(Visit::query()->count())->toBe(2);
});

test('the reception desk checks in appointments and walk-ins', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture);
    $walkInPatient = Patient::factory()->create();

    Livewire::actingAs($fixture['receptionist'])->test(ReceptionDesk::class)
        ->assertSee($fixture['patient']->last_name)
        ->call('checkIn', $appointment->id)
        ->assertHasNoErrors()
        ->call('selectPatient', $walkInPatient->id)
        ->set('service_id', (string) $fixture['service']->id)
        ->call('registerWalkIn')
        ->assertHasNoErrors()
        ->assertSet('patient_id', null);

    expect(Visit::query()->count())->toBe(2);
});

test('the reception desk closes a visit with a reason', function () {
    $fixture = bookingFixture();
    $visit = walkIn($fixture);

    Livewire::actingAs($fixture['receptionist'])->test(ReceptionDesk::class)
        ->call('startClosing', $visit->id, 'cancelled')
        ->set('closeReason', 'Registered in error')
        ->call('closeVisit')
        ->assertHasNoErrors();

    expect($visit->refresh()->status)->toBe(VisitStatus::Cancelled);
});
