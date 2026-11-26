<?php

use App\Actions\Schedules\CreateScheduleException;
use App\Actions\Schedules\DeleteScheduleException;
use App\Actions\Schedules\SaveProviderSchedule;
use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Livewire\Staff\ProviderSchedules;
use App\Livewire\Staff\ScheduleExceptions;
use App\Models\ProviderSchedule;
use App\Models\ProviderScheduleException;
use App\Models\Service;
use App\Models\StaffAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function scheduleInput(Service $service, array $overrides = []): array
{
    return ['service_id' => $service->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15, 'is_active' => true, ...$overrides];
}

test('an administrator adds a schedule block for an eligible provider', function () {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);

    Livewire::actingAs(admin())->test(ProviderSchedules::class, ['provider' => $doctor])
        ->set('service_id', (string) $service->id)
        ->set('day_of_week', '3')
        ->set('start_time', '13:00')
        ->set('end_time', '16:00')
        ->set('slot_duration_minutes', 20)
        ->call('save')
        ->assertHasNoErrors();

    $schedule = ProviderSchedule::query()->sole();

    expect($schedule->day_of_week)->toBe(DayOfWeek::Wednesday)
        ->and(substr($schedule->start_time, 0, 5))->toBe('13:00')
        ->and($schedule->provider_id)->toBe($doctor->id);
});

test('providers must hold a provider role, be active and be assigned to the service department', function () {
    $service = Service::factory()->appointable()->create();
    $save = app(SaveProviderSchedule::class);

    $receptionist = receptionist();
    StaffAssignment::factory()->create(['user_id' => $receptionist->id, 'department_id' => $service->department_id]);
    expectValidationError(fn () => $save->handle(admin(), $receptionist, scheduleInput($service)), 'provider');

    $inactive = providerIn($service->department);
    $inactive->forceFill(['is_active' => false])->save();
    expectValidationError(fn () => $save->handle(admin(), $inactive, scheduleInput($service)), 'provider');

    $unassigned = staffWithRole(Role::Nurse);
    expectValidationError(fn () => $save->handle(admin(), $unassigned, scheduleInput($service)), 'service_id');
});

test('the service must be active and accept appointments', function (string $state) {
    $service = $state === 'inactive'
        ? Service::factory()->appointable()->inactive()->create()
        : Service::factory()->create(['allows_appointments' => false]);

    expectValidationError(fn () => app(SaveProviderSchedule::class)->handle(admin(), providerIn($service->department), scheduleInput($service)), 'service_id');
})->with(['inactive', 'not appointable']);

test('schedule intervals must be valid', function (array $overrides, string $field) {
    $service = Service::factory()->appointable()->create();

    expectValidationError(fn () => app(SaveProviderSchedule::class)->handle(admin(), providerIn($service->department), scheduleInput($service, $overrides)), $field);
})->with([
    'end before start' => [['start_time' => '12:00', 'end_time' => '09:00'], 'end_time'],
    'zero length' => [['start_time' => '09:00', 'end_time' => '09:00'], 'end_time'],
    'partial slot' => [['end_time' => '12:10'], 'slot_duration_minutes'],
    'slot too short' => [['slot_duration_minutes' => 4], 'slot_duration_minutes'],
    'slot too long' => [['slot_duration_minutes' => 300], 'slot_duration_minutes'],
    'bad day' => [['day_of_week' => 8], 'day_of_week'],
    'bad time' => [['start_time' => '25:00'], 'start_time'],
]);

test('active blocks of a provider cannot overlap on the same day, even across services', function () {
    $service = Service::factory()->appointable()->create();
    $other = Service::factory()->appointable()->create(['department_id' => $service->department_id]);
    $doctor = providerIn($service->department);
    $save = app(SaveProviderSchedule::class);

    $save->handle(admin(), $doctor, scheduleInput($service));

    expectValidationError(fn () => $save->handle(admin(), $doctor, scheduleInput($other, ['start_time' => '11:00', 'end_time' => '13:00'])), 'start_time');

    // Half-open ranges: back-to-back blocks, other days and inactive blocks are fine.
    $save->handle(admin(), $doctor, scheduleInput($other, ['start_time' => '12:00', 'end_time' => '13:00']));
    $save->handle(admin(), $doctor, scheduleInput($service, ['day_of_week' => 2]));
    $save->handle(admin(), $doctor, scheduleInput($service, ['start_time' => '10:00', 'end_time' => '11:00', 'is_active' => false]));

    expect(ProviderSchedule::query()->count())->toBe(4);
});

test('the database exclusion constraint rejects overlapping active blocks', function () {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);
    $row = ['provider_id' => $doctor->id, 'service_id' => $service->id, 'day_of_week' => 1, 'slot_duration_minutes' => 15];

    DB::table('provider_schedules')->insert([...$row, 'start_time' => '09:00', 'end_time' => '12:00']);

    expectConstraintViolation(fn () => DB::table('provider_schedules')->insert([...$row, 'start_time' => '11:45', 'end_time' => '12:15']), 'provider_schedules_no_overlap');

    DB::table('provider_schedules')->insert([...$row, 'start_time' => '11:00', 'end_time' => '12:00', 'is_active' => false]);
    DB::table('provider_schedules')->insert([...$row, 'start_time' => '12:00', 'end_time' => '13:00']);

    expectConstraintViolation(fn () => DB::table('provider_schedules')->insert([...$row, 'start_time' => '14:00', 'end_time' => '14:10']), 'provider_schedules_whole_slots');
    expectConstraintViolation(fn () => DB::table('provider_schedules')->insert([...$row, 'start_time' => '15:00', 'end_time' => '14:00']), 'provider_schedules_end_after_start');
    expectConstraintViolation(fn () => DB::table('provider_schedules')->insert([...$row, 'day_of_week' => 0, 'start_time' => '15:00', 'end_time' => '16:00']), 'provider_schedules_day_of_week_iso');

    expect(DB::table('provider_schedules')->where('provider_id', $doctor->id)->count())->toBe(3);
});

test('a race past the application check surfaces as a validation error', function () {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);

    // Simulate a concurrent request: the constraint fires inside the save transaction.
    ProviderSchedule::saving(function (ProviderSchedule $schedule) use ($doctor, $service): void {
        ProviderSchedule::withoutEvents(fn () => DB::table('provider_schedules')->insert([
            'provider_id' => $doctor->id, 'service_id' => $service->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15,
        ]));
    });

    expectValidationError(fn () => app(SaveProviderSchedule::class)->handle(admin(), $doctor, scheduleInput($service)), 'start_time');
    expect(ProviderSchedule::query()->count())->toBe(0); // the whole transaction rolled back
});

test('deactivating a block through the component frees the slot', function () {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);
    $schedule = app(SaveProviderSchedule::class)->handle(admin(), $doctor, scheduleInput($service));

    Livewire::actingAs(admin())->test(ProviderSchedules::class, ['provider' => $doctor])
        ->call('toggleActive', $schedule->id)
        ->assertHasNoErrors();

    expect($schedule->refresh()->is_active)->toBeFalse();
});

test('exceptions are entered in clinic time and stored in utc', function () {
    config(['clinic.timezone' => 'Asia/Manila']);
    $doctor = staffWithRole(Role::Doctor);

    $exception = app(CreateScheduleException::class)->handle(admin(), $doctor, [
        'starts_at' => '2030-01-15T08:00', 'ends_at' => '2030-01-15T17:00', 'reason' => 'Annual leave',
    ]);

    expect($exception->refresh()->starts_at->utc()->toIso8601String())->toBe('2030-01-15T00:00:00+00:00')
        ->and(DB::selectOne('select starts_at::text as v from provider_schedule_exceptions')->v)->toBe('2030-01-15 00:00:00+00');
});

test('exceptions must end after they start and must not overlap', function () {
    $doctor = staffWithRole(Role::Doctor);
    $create = app(CreateScheduleException::class);

    expectValidationError(fn () => $create->handle(admin(), $doctor, ['starts_at' => '2030-01-15T10:00', 'ends_at' => '2030-01-15T09:00', 'reason' => 'x']), 'ends_at');

    $create->handle(admin(), $doctor, ['starts_at' => '2030-01-15T08:00', 'ends_at' => '2030-01-15T12:00', 'reason' => 'Training']);
    expectValidationError(fn () => $create->handle(admin(), $doctor, ['starts_at' => '2030-01-15T11:00', 'ends_at' => '2030-01-15T13:00', 'reason' => 'x']), 'starts_at');

    $create->handle(admin(), $doctor, ['starts_at' => '2030-01-15T12:00', 'ends_at' => '2030-01-15T13:00', 'reason' => 'Meeting']);
    expect(ProviderScheduleException::query()->count())->toBe(2);
});

test('only exceptions that have not started can be removed', function () {
    $doctor = staffWithRole(Role::Doctor);
    $future = ProviderScheduleException::factory()->create(['provider_id' => $doctor->id]);
    $past = ProviderScheduleException::factory()->create([
        'provider_id' => $doctor->id, 'starts_at' => Carbon::now()->subDays(3), 'ends_at' => Carbon::now()->subDays(2),
    ]);

    Livewire::actingAs(admin())->test(ScheduleExceptions::class, ['provider' => $doctor])->call('delete', $future->id);
    expect(ProviderScheduleException::query()->whereKey($future->id)->exists())->toBeFalse();

    expect(fn () => app(DeleteScheduleException::class)->handle(admin(), $past))->toThrow(AuthorizationException::class);
});

test('only administrators manage schedules', function (Role $role) {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);
    $user = staffWithRole($role);

    expect(fn () => app(SaveProviderSchedule::class)->handle($user, $doctor, scheduleInput($service)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateScheduleException::class)->handle($user, $doctor, ['starts_at' => '2030-01-15T08:00', 'ends_at' => '2030-01-15T09:00', 'reason' => 'x']))
        ->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);
