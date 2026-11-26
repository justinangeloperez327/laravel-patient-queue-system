<?php

use App\Actions\Appointments\BookAppointment;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against PostgreSQL (see phpunit.xml) so that database
| constraints, locking and timestamptz behaviour match production.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function staffWithRole(Role ...$roles): User
{
    return User::factory()->withRole(...$roles)->create();
}

function admin(): User
{
    return staffWithRole(Role::Admin);
}

function receptionist(): User
{
    return staffWithRole(Role::Receptionist);
}

/**
 * An active provider (doctor by default) assigned to the given departments.
 */
function providerIn(Department $department, Role $role = Role::Doctor): User
{
    $user = staffWithRole($role);
    StaffAssignment::factory()->create(['user_id' => $user->id, 'department_id' => $department->id]);

    return $user;
}

/**
 * Assert that a callback throws a validation error for the given field.
 */
function expectValidationError(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail("Expected a validation error for [{$field}].");
}

/**
 * Assert that a statement violates a database constraint. The statement runs
 * in a savepoint so the aborted statement does not poison the surrounding
 * test transaction (PostgreSQL aborts the whole transaction otherwise).
 */
function expectConstraintViolation(callable $statement, string $constraint): void
{
    try {
        DB::transaction(fn () => $statement());
    } catch (QueryException $exception) {
        expect((string) ($exception->errorInfo[0] ?? ''))->toStartWith('23')
            ->and($exception->getMessage())->toContain('"'.$constraint.'"');

        return;
    }

    test()->fail("Expected constraint [{$constraint}] to be violated.");
}

pest()->extend(TestCase::class)->in('Concurrency');

/**
 * A bookable setup on Monday 2030-01-07: doctor in the service's department
 * with a 09:00–12:00 block of 15-minute slots. The clock is set to 07:00 UTC
 * that day (clinic timezone UTC unless a test changes it).
 *
 * @return array{receptionist: User, doctor: User, service: Service, patient: Patient}
 */
function bookingFixture(): array
{
    test()->travelTo(CarbonImmutable::parse('2030-01-07 07:00:00', 'UTC'));

    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);
    ProviderSchedule::factory()->create([
        'provider_id' => $doctor->id, 'service_id' => $service->id, 'day_of_week' => DayOfWeek::Monday,
        'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15,
    ]);

    return [
        'receptionist' => receptionist(),
        'doctor' => $doctor,
        'service' => $service,
        'patient' => Patient::factory()->create(),
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $overrides
 */
function book(array $fixture, string $startUtc = '2030-01-07 09:00:00', array $overrides = []): Appointment
{
    return app(BookAppointment::class)->handle($fixture['receptionist'], [
        'patient_id' => $fixture['patient']->id,
        'service_id' => $fixture['service']->id,
        'provider_id' => $fixture['doctor']->id,
        'scheduled_start_at' => CarbonImmutable::parse($startUtc, 'UTC')->toIso8601String(),
        ...$overrides,
    ]);
}
