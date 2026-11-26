<?php

use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\Role;
use App\Livewire\Staff\StaffForm;
use App\Models\Department;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function staffInput(array $overrides = []): array
{
    return ['name' => 'Sam Staff', 'email' => 'sam@example.test', 'roles' => ['nurse'], 'department_ids' => [], 'is_active' => true, ...$overrides];
}

test('an administrator creates a staff member who is invited to set a password', function () {
    Notification::fake();
    $department = Department::factory()->create();

    Livewire::actingAs(admin())->test(StaffForm::class)
        ->set('name', 'Sam Staff')
        ->set('email', 'Sam@Example.test')
        ->set('roles', ['nurse'])
        ->set('department_ids', [(string) $department->id])
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'sam@example.test')->sole();

    expect($user->hasRole('nurse'))->toBeTrue()
        ->and($user->departments()->pluck('departments.id')->all())->toBe([$department->id]);
    Notification::assertSentTo($user, ResetPassword::class);
});

test('staff need at least one valid role', function (array $roles, string $field) {
    expectValidationError(fn () => app(CreateStaffUser::class)->handle(staffInput(['roles' => $roles]), admin()), $field);
})->with([
    'no roles' => [[], 'roles'],
    'unknown role' => [['superuser'], 'roles.0'],
]);

test('inactive departments cannot be assigned to new staff', function () {
    $inactive = Department::factory()->inactive()->create();

    expectValidationError(fn () => app(CreateStaffUser::class)->handle(staffInput(['department_ids' => [$inactive->id]]), admin()), 'department_ids.0');
});

test('an administrator updates roles, status and departments, keeping assignment history', function () {
    $admin = admin();
    [$first, $second] = Department::factory()->count(2)->create();
    $staff = User::factory()->withRole(Role::Nurse)->create();
    StaffAssignment::factory()->create(['user_id' => $staff->id, 'department_id' => $first->id]);

    app(UpdateStaffUser::class)->handle($admin, $staff, staffInput([
        'email' => $staff->email, 'roles' => ['doctor', 'receptionist'], 'department_ids' => [$second->id], 'is_active' => false,
    ]));

    $staff->refresh();

    expect($staff->getRoleNames()->sort()->values()->all())->toBe(['doctor', 'receptionist'])
        ->and($staff->is_active)->toBeFalse()
        ->and($staff->departments()->pluck('departments.id')->all())->toBe([$second->id])
        ->and(StaffAssignment::query()->where('user_id', $staff->id)->where('department_id', $first->id)->value('is_active'))->toBeFalse();
});

test('re-assigning a department reactivates the existing assignment row', function () {
    $department = Department::factory()->create();
    $staff = User::factory()->withRole(Role::Nurse)->create();
    StaffAssignment::factory()->create(['user_id' => $staff->id, 'department_id' => $department->id, 'is_active' => false]);

    app(UpdateStaffUser::class)->handle(admin(), $staff, staffInput(['email' => $staff->email, 'department_ids' => [$department->id]]));

    expect(StaffAssignment::query()->where('user_id', $staff->id)->count())->toBe(1)
        ->and($staff->isAssignedToDepartment($department->id))->toBeTrue();
});

test('administrators cannot lock themselves out', function () {
    $admin = admin();

    expectValidationError(fn () => app(UpdateStaffUser::class)->handle($admin, $admin, staffInput(['email' => $admin->email, 'roles' => ['admin'], 'is_active' => false])), 'is_active');
    expectValidationError(fn () => app(UpdateStaffUser::class)->handle($admin, $admin, staffInput(['email' => $admin->email, 'roles' => ['nurse']])), 'roles');
});

test('a provider with active schedules keeps the role and department those schedules need', function () {
    $service = Service::factory()->appointable()->create();
    $doctor = providerIn($service->department);
    ProviderSchedule::factory()->create(['provider_id' => $doctor->id, 'service_id' => $service->id]);
    $base = ['email' => $doctor->email];

    expectValidationError(fn () => app(UpdateStaffUser::class)->handle(admin(), $doctor, staffInput([...$base, 'roles' => ['receptionist'], 'department_ids' => [$service->department_id]])), 'roles');
    expectValidationError(fn () => app(UpdateStaffUser::class)->handle(admin(), $doctor, staffInput([...$base, 'roles' => ['doctor'], 'department_ids' => []])), 'department_ids');
});

test('staff emails stay unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.test']);
    $staff = User::factory()->withRole(Role::Nurse)->create();

    expectValidationError(fn () => app(UpdateStaffUser::class)->handle(admin(), $staff, staffInput(['email' => 'TAKEN@example.test'])), 'email');
});

test('only administrators can manage staff', function (Role $role) {
    $user = staffWithRole($role);
    $other = User::factory()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($user)->get(route('staff.edit', $other))->assertForbidden();
    expect(fn () => app(UpdateStaffUser::class)->handle($user, $other, staffInput()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateStaffUser::class)->handle(staffInput(), $user))->toThrow(AuthorizationException::class);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('the staff edit page shows schedules only for providers', function () {
    $admin = admin();
    $doctor = staffWithRole(Role::Doctor);
    $receptionist = receptionist();

    $this->actingAs($admin)->get(route('staff.edit', $doctor))->assertOk()->assertSee('Weekly schedule');
    $this->actingAs($admin)->get(route('staff.edit', $receptionist))->assertOk()->assertDontSee('Weekly schedule');
    $this->actingAs($admin)->get(route('staff.index'))->assertOk()->assertSee($doctor->name);
});
