<?php

use App\Actions\Services\SaveService;
use App\Enums\Role;
use App\Enums\TriageMode;
use App\Livewire\Services\ServiceForm;
use App\Models\Counter;
use App\Models\Department;
use App\Models\ProviderSchedule;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function serviceInput(Department $department, array $overrides = []): array
{
    return [
        'department_id' => $department->id,
        'name' => 'Consultation',
        'queue_prefix' => 'c',
        'triage_mode' => 'none',
        'triage_service_id' => null,
        'allows_appointments' => false,
        'estimated_duration_minutes' => 15,
        'is_active' => true,
        ...$overrides,
    ];
}

function saveService(array $input, ?Service $service = null): Service
{
    return app(SaveService::class)->handle(admin(), $input, $service);
}

test('an administrator creates a service routed through triage via the form', function () {
    $department = Department::factory()->create();
    $triage = Service::factory()->create(['queue_prefix' => 'T']);

    Livewire::actingAs(admin())->test(ServiceForm::class)
        ->set('department_id', (string) $department->id)
        ->set('name', 'Consultation')
        ->set('queue_prefix', 'c')
        ->set('triage_mode', 'required')
        ->set('triage_service_id', (string) $triage->id)
        ->set('allows_appointments', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('services.index'));

    $service = Service::query()->where('queue_prefix', 'C')->sole();

    expect($service->triage_mode)->toBe(TriageMode::Required)
        ->and($service->triageService?->is($triage))->toBeTrue()
        ->and($service->allows_appointments)->toBeTrue();
});

test('triage routing requires a triage service, and no triage clears it', function () {
    $department = Department::factory()->create();
    $triage = Service::factory()->create();

    expectValidationError(fn () => saveService(serviceInput($department, ['triage_mode' => 'optional'])), 'triage_service_id');

    $service = saveService(serviceInput($department, ['triage_mode' => 'none', 'triage_service_id' => $triage->id]));
    expect($service->triage_service_id)->toBeNull();
});

test('the triage service must be active and must not require triage itself', function () {
    $department = Department::factory()->create();
    $inactive = Service::factory()->inactive()->create();
    $root = Service::factory()->create();
    $routed = Service::factory()->triagedBy($root)->create();

    expectValidationError(fn () => saveService(serviceInput($department, ['triage_mode' => 'required', 'triage_service_id' => $inactive->id])), 'triage_service_id');
    expectValidationError(fn () => saveService(serviceInput($department, ['triage_mode' => 'required', 'triage_service_id' => $routed->id])), 'triage_service_id');
});

test('a service cannot be its own triage service', function () {
    $service = Service::factory()->create();

    expectValidationError(fn () => saveService(serviceInput($service->department, [
        'queue_prefix' => $service->queue_prefix, 'name' => $service->name, 'triage_mode' => 'required', 'triage_service_id' => $service->id,
    ]), $service), 'triage_service_id');
});

test('a triage service used by others cannot gain triage or be deactivated', function () {
    $triage = Service::factory()->create();
    Service::factory()->triagedBy($triage)->create();
    $other = Service::factory()->create();
    $base = ['queue_prefix' => $triage->queue_prefix, 'name' => $triage->name];

    expectValidationError(fn () => saveService(serviceInput($triage->department, [...$base, 'triage_mode' => 'optional', 'triage_service_id' => $other->id]), $triage), 'triage_mode');
    expectValidationError(fn () => saveService(serviceInput($triage->department, [...$base, 'is_active' => false]), $triage), 'is_active');
});

test('a triage service can be deactivated once its routed services are inactive', function () {
    $triage = Service::factory()->create();
    Service::factory()->triagedBy($triage)->inactive()->create();

    saveService(serviceInput($triage->department, ['queue_prefix' => $triage->queue_prefix, 'name' => $triage->name, 'is_active' => false]), $triage);

    expect($triage->refresh()->is_active)->toBeFalse();
});

test('queue prefixes are uppercase letters and unique', function () {
    $department = Department::factory()->create();
    saveService(serviceInput($department, ['queue_prefix' => 'lab', 'name' => 'Lab']));

    expect(Service::query()->value('queue_prefix'))->toBe('LAB');
    expectValidationError(fn () => saveService(serviceInput($department, ['queue_prefix' => 'Lab', 'name' => 'Other'])), 'queue_prefix');

    foreach (['', 'ABCD', 'A1', 'Ä'] as $prefix) {
        expectValidationError(fn () => saveService(serviceInput($department, ['queue_prefix' => $prefix, 'name' => 'X'.$prefix])), 'queue_prefix');
    }
});

test('service names are unique within a department regardless of case', function () {
    $department = Department::factory()->create();
    saveService(serviceInput($department, ['name' => 'Dressing', 'queue_prefix' => 'D']));

    expectValidationError(fn () => saveService(serviceInput($department, ['name' => 'DRESSING', 'queue_prefix' => 'E'])), 'name');

    $other = Department::factory()->create();
    expect(saveService(serviceInput($other, ['name' => 'Dressing', 'queue_prefix' => 'F']))->exists)->toBeTrue();
});

test('estimated duration must be between 1 and 480 minutes', function (int $minutes) {
    expectValidationError(fn () => saveService(serviceInput(Department::factory()->create(), ['estimated_duration_minutes' => $minutes])), 'estimated_duration_minutes');
})->with([0, 481]);

test('a new service needs an active department', function () {
    expectValidationError(fn () => saveService(serviceInput(Department::factory()->inactive()->create())), 'department_id');
});

test('a service on counters or with active schedules cannot change department', function (string $dependent) {
    $service = Service::factory()->appointable()->create();

    if ($dependent === 'counter') {
        $counter = Counter::factory()->create(['department_id' => $service->department_id]);
        $counter->services()->attach($service->id, ['department_id' => $service->department_id]);
    } else {
        ProviderSchedule::factory()->create(['service_id' => $service->id, 'provider_id' => providerIn($service->department)->id]);
    }

    expectValidationError(fn () => saveService(serviceInput(Department::factory()->create(), [
        'queue_prefix' => $service->queue_prefix, 'name' => $service->name, 'allows_appointments' => true,
    ]), $service), 'department_id');
})->with(['counter', 'schedule']);

test('appointments cannot be disabled while active schedules exist', function () {
    $service = Service::factory()->appointable()->create();
    ProviderSchedule::factory()->create(['service_id' => $service->id, 'provider_id' => providerIn($service->department)->id]);

    expectValidationError(fn () => saveService(serviceInput($service->department, [
        'queue_prefix' => $service->queue_prefix, 'name' => $service->name, 'allows_appointments' => false,
    ]), $service), 'allows_appointments');
});

test('the database enforces triage mode and target consistency', function () {
    $department = Department::factory()->create();
    $triage = Service::factory()->create();
    $row = ['department_id' => $department->id, 'name' => 'X', 'estimated_duration_minutes' => 10];

    expectConstraintViolation(fn () => DB::table('services')->insert([...$row, 'queue_prefix' => 'QA', 'triage_mode' => 'required']), 'services_triage_target_matches_mode');
    expectConstraintViolation(fn () => DB::table('services')->insert([...$row, 'queue_prefix' => 'QB', 'triage_mode' => 'none', 'triage_service_id' => $triage->id]), 'services_triage_target_matches_mode');
    expectConstraintViolation(fn () => DB::table('services')->insert([...$row, 'queue_prefix' => 'QC', 'triage_mode' => 'sometimes']), 'services_triage_mode_valid');
    expectConstraintViolation(fn () => DB::table('services')->insert([...$row, 'queue_prefix' => 'qd', 'triage_mode' => 'none']), 'services_queue_prefix_format');
    expectConstraintViolation(fn () => DB::table('services')->insert([...$row, 'queue_prefix' => 'QE', 'estimated_duration_minutes' => 0]), 'services_estimated_duration_range');
    expectConstraintViolation(fn () => DB::table('services')->where('id', $triage->id)->update(['triage_mode' => 'required', 'triage_service_id' => $triage->id]), 'services_triage_not_self');

    // The valid row is still accepted afterwards (the test transaction is intact).
    expect(DB::table('services')->insert([...$row, 'queue_prefix' => 'QF', 'triage_mode' => 'optional', 'triage_service_id' => $triage->id]))->toBeTrue();
});

test('only administrators can manage services', function (Role $role) {
    $user = staffWithRole($role);
    $service = Service::factory()->create();

    $this->actingAs($user)->get(route('services.index'))->assertForbidden();
    $this->actingAs($user)->get(route('services.edit', $service))->assertForbidden();
    expect(fn () => app(SaveService::class)->handle($user, serviceInput($service->department)))->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('administrators can open service screens', function () {
    $admin = admin();
    $service = Service::factory()->create(['name' => 'Dental check']);

    $this->actingAs($admin)->get(route('services.index'))->assertOk()->assertSee('Dental check');
    $this->actingAs($admin)->get(route('services.create'))->assertOk();
    $this->actingAs($admin)->get(route('services.edit', $service))->assertOk();
});
