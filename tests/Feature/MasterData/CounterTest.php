<?php

use App\Actions\Counters\SaveCounter;
use App\Enums\Role;
use App\Livewire\Counters\CounterForm;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function counterInput(Department $department, array $overrides = []): array
{
    return ['department_id' => $department->id, 'code' => 'room-1', 'name' => 'Room 1', 'location' => null, 'is_active' => true, 'service_ids' => [], ...$overrides];
}

test('an administrator creates a counter serving services of its department', function () {
    $department = Department::factory()->create();
    $services = Service::factory()->count(2)->for($department)->create();

    Livewire::actingAs(admin())->test(CounterForm::class)
        ->set('department_id', (string) $department->id)
        ->set('code', 'room-1')
        ->set('name', 'Room 1')
        ->set('service_ids', $services->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('counters.index'));

    $counter = Counter::query()->sole();

    expect($counter->code)->toBe('ROOM-1')
        ->and($counter->services()->pluck('services.id')->sort()->values()->all())->toBe($services->pluck('id')->sort()->values()->all());
});

test('a counter cannot serve another department\'s service', function () {
    $department = Department::factory()->create();
    $foreign = Service::factory()->create();

    expectValidationError(fn () => app(SaveCounter::class)->handle(admin(), counterInput($department, ['service_ids' => [$foreign->id]])), 'service_ids');
});

test('the database rejects cross-department counter services', function () {
    $counter = Counter::factory()->create();
    $foreign = Service::factory()->create();

    expectConstraintViolation(fn () => DB::table('counter_services')->insert([
        'counter_id' => $counter->id, 'service_id' => $foreign->id, 'department_id' => $counter->department_id,
    ]), 'counter_services_service_id_department_id_foreign');

    expectConstraintViolation(fn () => DB::table('counter_services')->insert([
        'counter_id' => $counter->id, 'service_id' => $foreign->id, 'department_id' => $foreign->department_id,
    ]), 'counter_services_counter_id_department_id_foreign');
});

test('inactive services cannot be newly added to a counter', function () {
    $department = Department::factory()->create();
    $inactive = Service::factory()->for($department)->inactive()->create();

    expectValidationError(fn () => app(SaveCounter::class)->handle(admin(), counterInput($department, ['service_ids' => [$inactive->id]])), 'service_ids');
});

test('moving a counter to another department replaces its services', function () {
    $admin = admin();
    $from = Department::factory()->create();
    $to = Department::factory()->create();
    $old = Service::factory()->for($from)->create();
    $new = Service::factory()->for($to)->create();
    $counter = app(SaveCounter::class)->handle($admin, counterInput($from, ['service_ids' => [$old->id]]));

    app(SaveCounter::class)->handle($admin, counterInput($to, ['code' => $counter->code, 'service_ids' => [$new->id]]), $counter);

    expect($counter->refresh()->department_id)->toBe($to->id)
        ->and($counter->services()->pluck('services.id')->all())->toBe([$new->id]);
});

test('counter codes are unique', function () {
    $department = Department::factory()->create();
    app(SaveCounter::class)->handle(admin(), counterInput($department));

    expectValidationError(fn () => app(SaveCounter::class)->handle(admin(), counterInput($department, ['code' => 'ROOM-1', 'name' => 'Other'])), 'code');
});

test('changing the department in the form clears selected services', function () {
    $department = Department::factory()->create();
    $service = Service::factory()->for($department)->create();

    Livewire::actingAs(admin())->test(CounterForm::class)
        ->set('department_id', (string) $department->id)
        ->set('service_ids', [(string) $service->id])
        ->set('department_id', (string) Department::factory()->create()->id)
        ->assertSet('service_ids', []);
});

test('only administrators can manage counters', function (Role $role) {
    $user = staffWithRole($role);
    $counter = Counter::factory()->create();

    $this->actingAs($user)->get(route('counters.index'))->assertForbidden();
    $this->actingAs($user)->get(route('counters.edit', $counter))->assertForbidden();
    expect(fn () => app(SaveCounter::class)->handle($user, counterInput($counter->department)))->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('administrators can open counter screens', function () {
    $admin = admin();
    $counter = Counter::factory()->create(['code' => 'DESK-9']);

    $this->actingAs($admin)->get(route('counters.index'))->assertOk()->assertSee('DESK-9');
    $this->actingAs($admin)->get(route('counters.create'))->assertOk();
    $this->actingAs($admin)->get(route('counters.edit', $counter))->assertOk();
});
