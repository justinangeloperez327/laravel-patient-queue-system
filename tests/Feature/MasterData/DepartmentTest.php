<?php

use App\Actions\Departments\SaveDepartment;
use App\Enums\Role;
use App\Livewire\Departments\DepartmentForm;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function departmentInput(array $overrides = []): array
{
    return ['code' => 'opd', 'name' => 'Outpatients', 'description' => null, 'is_active' => true, ...$overrides];
}

test('an administrator creates and updates a department through the form', function () {
    $admin = admin();

    Livewire::actingAs($admin)->test(DepartmentForm::class)
        ->set('code', ' opd ')
        ->set('name', 'Outpatients')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('departments.index'));

    $department = Department::query()->sole();
    expect($department->code)->toBe('OPD');

    Livewire::actingAs($admin)->test(DepartmentForm::class, ['department' => $department])
        ->set('name', 'Outpatient clinic')
        ->call('save')
        ->assertHasNoErrors();

    expect($department->refresh()->name)->toBe('Outpatient clinic');
});

test('department codes and names are unique regardless of case', function () {
    $admin = admin();
    app(SaveDepartment::class)->handle($admin, departmentInput());

    expectValidationError(fn () => app(SaveDepartment::class)->handle($admin, departmentInput(['name' => 'Other'])), 'code');
    expectValidationError(fn () => app(SaveDepartment::class)->handle($admin, departmentInput(['code' => 'X1', 'name' => 'OUTPATIENTS'])), 'name');
});

test('department codes must use the allowed characters', function (string $code) {
    expectValidationError(fn () => app(SaveDepartment::class)->handle(admin(), departmentInput(['code' => $code])), 'code');
})->with(['with space', '-LEADING', 'ÄBC', str_repeat('A', 21)]);

test('the database rejects lowercase codes and case-insensitive duplicate names', function () {
    expectConstraintViolation(fn () => DB::table('departments')->insert(['code' => 'lower', 'name' => 'A']), 'departments_code_format');

    Department::factory()->create(['name' => 'Radiology']);
    expectConstraintViolation(fn () => DB::table('departments')->insert(['code' => 'RAD2', 'name' => 'RADIOLOGY']), 'departments_name_lower_unique');
});

test('a department with active services or counters cannot be deactivated', function (string $dependent) {
    $admin = admin();
    $department = Department::factory()->create();
    $dependent === 'service'
        ? Service::factory()->for($department)->create()
        : Counter::factory()->for($department)->create();

    expectValidationError(fn () => app(SaveDepartment::class)->handle($admin, departmentInput([
        'code' => $department->code, 'name' => $department->name, 'is_active' => false,
    ]), $department), 'is_active');
})->with(['service', 'counter']);

test('a department with only inactive dependents can be deactivated', function () {
    $department = Department::factory()->create();
    Service::factory()->for($department)->inactive()->create();

    app(SaveDepartment::class)->handle(admin(), departmentInput([
        'code' => $department->code, 'name' => $department->name, 'is_active' => false,
    ]), $department);

    expect($department->refresh()->is_active)->toBeFalse();
});

test('only administrators can manage departments', function (Role $role) {
    $user = staffWithRole($role);
    $department = Department::factory()->create();

    $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
    $this->actingAs($user)->get(route('departments.edit', $department))->assertForbidden();
    expect(fn () => app(SaveDepartment::class)->handle($user, departmentInput()))->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('administrators can open department screens', function () {
    $admin = admin();
    $department = Department::factory()->create(['name' => 'Radiology']);

    $this->actingAs($admin)->get(route('departments.index'))->assertOk()->assertSee('Radiology');
    $this->actingAs($admin)->get(route('departments.create'))->assertOk();
    $this->actingAs($admin)->get(route('departments.edit', $department))->assertOk();
});
