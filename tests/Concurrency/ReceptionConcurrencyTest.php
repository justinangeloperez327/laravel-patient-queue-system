<?php

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Models\Visit;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Tests\Concurrency\Harness;

/*
| These tests start several PHP processes that perform the same reception
| action at the same instant against committed PostgreSQL data, proving the
| database constraints (not just application checks) prevent duplicates.
*/

beforeEach(fn () => Harness::resetDatabase());
afterEach(fn () => Harness::resetDatabase());

const WORKERS = 6;

function concurrencyFixture(): array
{
    $receptionist = User::factory()->withRole(Role::Receptionist)->create();
    $service = Service::factory()->appointable()->create();
    $doctor = User::factory()->withRole(Role::Doctor)->create();
    StaffAssignment::factory()->create(['user_id' => $doctor->id, 'department_id' => $service->department_id]);

    // A slot next week on a fixed weekday, well inside the booking horizon.
    $day = ClinicTime::today()->addWeek()->startOfWeek();
    ProviderSchedule::factory()->create([
        'provider_id' => $doctor->id, 'service_id' => $service->id, 'day_of_week' => DayOfWeek::from($day->isoWeekday()),
        'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15,
    ]);

    return [$receptionist, $service, $doctor, CarbonImmutable::parse($day->toDateString().' 09:00', ClinicTime::timezone())->utc()];
}

function successes(array $results): array
{
    return array_values(array_filter($results, fn (array $result) => $result['ok'] === true));
}

function unexpectedFailures(array $results, string $expectedField): array
{
    return array_values(array_filter($results, fn (array $result) => $result['ok'] === false && ! array_key_exists($expectedField, $result['errors'] ?? [])));
}

test('simultaneous bookings of one slot produce exactly one appointment', function () {
    [$receptionist, $service, $doctor, $slot] = concurrencyFixture();
    $patients = Patient::factory()->count(WORKERS)->create(['registered_by' => $receptionist->id]);

    $results = Harness::race('book', $patients->map(fn (Patient $patient) => [
        'actor_id' => $receptionist->id,
        'input' => ['patient_id' => $patient->id, 'service_id' => $service->id, 'provider_id' => $doctor->id, 'scheduled_start_at' => $slot->toIso8601String()],
    ])->all());

    expect(successes($results))->toHaveCount(1)
        ->and(unexpectedFailures($results, 'scheduled_start_at'))->toBe([])
        ->and(Appointment::query()->count())->toBe(1);
});

test('simultaneous bookings of different slots all succeed with unique sequential numbers', function () {
    [$receptionist, $service, $doctor, $slot] = concurrencyFixture();
    $patients = Patient::factory()->count(WORKERS)->create(['registered_by' => $receptionist->id]);

    $results = Harness::race('book', $patients->values()->map(fn (Patient $patient, int $i) => [
        'actor_id' => $receptionist->id,
        'input' => ['patient_id' => $patient->id, 'service_id' => $service->id, 'provider_id' => $doctor->id, 'scheduled_start_at' => $slot->addMinutes(15 * $i)->toIso8601String()],
    ])->all());

    $numbers = collect(successes($results))->pluck('number')->sort()->values()->all();
    $stem = 'APT-'.ClinicTime::today()->format('Ymd').'-';

    expect($numbers)->toBe(array_map(fn (int $n) => $stem.sprintf('%04d', $n), range(1, WORKERS)));
});

test('one patient booked into overlapping slots concurrently gets one appointment', function () {
    [$receptionist, $service, $doctor, $slot] = concurrencyFixture();
    $otherDoctor = User::factory()->withRole(Role::Doctor)->create();
    StaffAssignment::factory()->create(['user_id' => $otherDoctor->id, 'department_id' => $service->department_id]);
    ProviderSchedule::factory()->create([
        'provider_id' => $otherDoctor->id, 'service_id' => $service->id, 'day_of_week' => DayOfWeek::from($slot->setTimezone(ClinicTime::timezone())->isoWeekday()),
        'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15,
    ]);
    $patient = Patient::factory()->create(['registered_by' => $receptionist->id]);

    $results = Harness::race('book', collect(range(1, WORKERS))->map(fn (int $i) => [
        'actor_id' => $receptionist->id,
        'input' => ['patient_id' => $patient->id, 'service_id' => $service->id, 'provider_id' => $i % 2 === 0 ? $doctor->id : $otherDoctor->id, 'scheduled_start_at' => $slot->toIso8601String()],
    ])->all());

    expect(successes($results))->toHaveCount(1)
        ->and(Appointment::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

test('simultaneous check-ins of one appointment create exactly one visit', function () {
    [$receptionist, $service, $doctor] = concurrencyFixture();
    $start = CarbonImmutable::now()->addMinutes(30)->startOfMinute();
    $appointment = Appointment::factory()->create([
        'service_id' => $service->id, 'provider_id' => $doctor->id, 'booked_by' => $receptionist->id,
        'scheduled_start_at' => $start, 'scheduled_end_at' => $start->addMinutes(15),
    ]);

    if (! $appointment->localDate()->equalTo(ClinicTime::today())) {
        $this->markTestSkipped('Too close to the end of the clinic day to schedule a same-day appointment.');
    }

    $results = Harness::race('check-in', array_fill(0, WORKERS, ['actor_id' => $receptionist->id, 'appointment_id' => $appointment->id]));

    expect(successes($results))->toHaveCount(1)
        ->and(array_filter($results, fn (array $r) => $r['ok'] === false && array_intersect(array_keys($r['errors'] ?? []), ['appointment', 'patient_id']) === []))->toBe([])
        ->and(Visit::query()->count())->toBe(1)
        ->and($appointment->refresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

test('simultaneous walk-in check-ins of one patient create exactly one open visit', function () {
    [$receptionist, $service] = concurrencyFixture();
    $patient = Patient::factory()->create(['registered_by' => $receptionist->id]);

    $results = Harness::race('walk-in', array_fill(0, WORKERS, [
        'actor_id' => $receptionist->id,
        'input' => ['patient_id' => $patient->id, 'service_id' => $service->id],
    ]));

    expect(successes($results))->toHaveCount(1)
        ->and(unexpectedFailures($results, 'patient_id'))->toBe([])
        ->and(Visit::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

test('simultaneous walk-ins of different patients all succeed with unique ticket numbers', function () {
    [$receptionist, $service] = concurrencyFixture();
    $patients = Patient::factory()->count(WORKERS)->create(['registered_by' => $receptionist->id]);

    $results = Harness::race('walk-in', $patients->map(fn (Patient $patient) => [
        'actor_id' => $receptionist->id,
        'input' => ['patient_id' => $patient->id, 'service_id' => $service->id],
    ])->all());

    $prefix = $service->queue_prefix;

    expect(successes($results))->toHaveCount(WORKERS)
        ->and(collect(successes($results))->pluck('number')->sort()->values()->all())
        ->toBe(array_map(fn (int $n) => sprintf('%s-%03d', $prefix, $n), range(1, WORKERS)))
        ->and(Visit::query()->pluck('visit_number')->unique())->toHaveCount(WORKERS);
});
