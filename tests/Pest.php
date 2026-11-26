<?php

use App\Actions\Appointments\BookAppointment;
use App\Actions\Queues\CallNextTicket;
use App\Actions\Queues\OpenCounterSession;
use App\Actions\Queues\StartTicket;
use App\Actions\Queues\TransferTicket;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\Department;
use App\Models\Patient;
use App\Models\ProviderSchedule;
use App\Models\QueueTicket;
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

require_once __DIR__.'/Support/ReportFixture.php';

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

/**
 * A small clinic for queue tests on Monday 2030-01-07 08:00 UTC:
 * NUR department with triage service T (counter TRIAGE-1, nurse), OPD with
 * consultation C requiring triage (counters ROOM-1 and ROOM-2, doctor), LAB
 * with L and PHR with P (counter for each, service staff assigned to both).
 *
 * @return array<string, mixed>
 */
function queueFixture(): array
{
    test()->travelTo(CarbonImmutable::parse('2030-01-07 08:00:00', 'UTC'));

    $dept = fn (string $code) => Department::factory()->create(['code' => $code, 'name' => $code.' department']);
    [$nur, $opd, $lab, $phr] = [$dept('NUR'), $dept('OPD'), $dept('LAB'), $dept('PHR')];

    $t = Service::factory()->create(['department_id' => $nur->id, 'queue_prefix' => 'T', 'name' => 'Triage']);
    $c = Service::factory()->appointable()->triagedBy($t)->create(['department_id' => $opd->id, 'queue_prefix' => 'C', 'name' => 'Consultation']);
    $l = Service::factory()->create(['department_id' => $lab->id, 'queue_prefix' => 'L', 'name' => 'Laboratory']);
    $p = Service::factory()->create(['department_id' => $phr->id, 'queue_prefix' => 'P', 'name' => 'Pharmacy']);

    $counter = function (Service $service, string $code) {
        $counter = Counter::factory()->create(['department_id' => $service->department_id, 'code' => $code, 'name' => $code]);
        $counter->services()->attach($service->id, ['department_id' => $service->department_id]);

        return $counter;
    };

    $nurse = providerIn($nur, Role::Nurse);
    $doctor = providerIn($opd);
    $serviceStaff = providerIn($lab, Role::ServiceStaff);
    StaffAssignment::factory()->create(['user_id' => $serviceStaff->id, 'department_id' => $phr->id]);

    return [
        'receptionist' => receptionist(),
        'nurse' => $nurse,
        'doctor' => $doctor,
        'serviceStaff' => $serviceStaff,
        'departments' => compact('nur', 'opd', 'lab', 'phr'),
        'services' => compact('t', 'c', 'l', 'p'),
        'counters' => [
            'triage' => $counter($t, 'TRIAGE-1'),
            'room1' => $counter($c, 'ROOM-1'),
            'room2' => $counter($c, 'ROOM-2'),
            'lab' => $counter($l, 'LAB-1'),
            'pharmacy' => $counter($p, 'PHARM-1'),
        ],
    ];
}

/**
 * Walk a patient in and return their first ticket.
 *
 * @param  array<string, mixed>  $fixture
 */
function walkInTicket(array $fixture, ?Service $service = null, ?Patient $patient = null, bool $sendToTriage = false): QueueTicket
{
    $visit = app(RegisterWalkIn::class)->handle($fixture['receptionist'], [
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
        'service_id' => ($service ?? $fixture['services']['l'])->id,
        'send_to_triage' => $sendToTriage,
    ]);

    return $visit->tickets()->sole();
}

function openCounter(User $user, Counter $counter): CounterSession
{
    return app(OpenCounterSession::class)->handle($user, $counter);
}

function callNext(User $user, Counter $counter, ?string $key = null): QueueTicket
{
    return app(CallNextTicket::class)->handle($user, $counter, $key);
}

/**
 * @param  class-string  $action
 */
function act(string $action, User $user, QueueTicket $ticket, ?string $key = null): QueueTicket
{
    return app($action)->handle($user, $ticket, $key);
}

/**
 * queueFixture() plus a walk-in for consultation (routed to triage) whose
 * triage ticket the nurse is now serving at TRIAGE-1.
 *
 * @return array<string, mixed>
 */
function clinicalFixture(): array
{
    $fixture = queueFixture();
    $fixture['patient'] = Patient::factory()->create(['last_name' => 'Synthetic', 'first_name' => 'Pat']);
    walkInTicket($fixture, $fixture['services']['c'], $fixture['patient']);
    openCounter($fixture['nurse'], $fixture['counters']['triage']);
    $fixture['triageTicket'] = act(StartTicket::class, $fixture['nurse'], callNext($fixture['nurse'], $fixture['counters']['triage']));

    return $fixture;
}

/**
 * Transfer the triage ticket to consultation and have the doctor serve it at ROOM-1.
 *
 * @param  array<string, mixed>  $fixture
 */
function toConsultation(array $fixture): QueueTicket
{
    app(TransferTicket::class)->handle($fixture['nurse'], $fixture['triageTicket'], $fixture['services']['c']->id);
    openCounter($fixture['doctor'], $fixture['counters']['room1']);

    return act(StartTicket::class, $fixture['doctor'], callNext($fixture['doctor'], $fixture['counters']['room1']));
}
