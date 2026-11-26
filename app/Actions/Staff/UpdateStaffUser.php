<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Updates a staff member's profile, roles, active flag and department
 * assignments. Guards against administrators locking themselves out and
 * against leaving active provider schedules outside the provider's scope.
 */
final class UpdateStaffUser
{
    use ProfileValidationRules;
    use SyncsStaffAssignments;

    /**
     * @param  array<string, mixed>  $input  name, email, roles, department_ids, is_active
     */
    public function handle(User $actor, User $staff, array $input): User
    {
        Gate::forUser($actor)->authorize('update', $staff);

        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        /** @var array{name: string, email: string, roles: list<string>, department_ids: list<int>, is_active: bool} $data */
        $data = Validator::make($input, [
            ...$this->profileRules($staff->id),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::enum(Role::class)],
            'department_ids' => ['present', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $departmentIds = array_map('intval', $data['department_ids']);

        if ($actor->is($staff)) {
            $this->guardSelfLockout($data);
        }

        return DB::transaction(function () use ($staff, $data, $departmentIds): User {
            User::query()->whereKey($staff->id)->lockForUpdate()->first();

            $this->guardNewDepartmentsAreActive($staff, $departmentIds);
            $this->guardActiveSchedules($staff, $data['roles'], $departmentIds);

            $staff->fill(['name' => $data['name'], 'email' => $data['email']]);
            $staff->is_active = (bool) $data['is_active'];
            $staff->save();

            $staff->syncRoles($data['roles']);
            $this->syncAssignments($staff, $departmentIds);

            return $staff;
        });
    }

    /**
     * @param  array{roles: list<string>, is_active: bool}  $data
     */
    private function guardSelfLockout(array $data): void
    {
        if (! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
        }

        if (! in_array(Role::Admin->value, $data['roles'], true)) {
            throw ValidationException::withMessages(['roles' => __('You cannot remove your own administrator role.')]);
        }
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function guardNewDepartmentsAreActive(User $staff, array $departmentIds): void
    {
        $current = $staff->departments()->pluck('departments.id')->all();
        $added = array_diff($departmentIds, $current);

        if ($added !== [] && DB::table('departments')->whereIn('id', $added)->where('is_active', false)->exists()) {
            throw ValidationException::withMessages(['department_ids' => __('Only active departments can be assigned.')]);
        }
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $departmentIds
     */
    private function guardActiveSchedules(User $staff, array $roles, array $departmentIds): void
    {
        $activeSchedules = $staff->providerSchedules()->where('is_active', true);

        if (! $activeSchedules->exists()) {
            return;
        }

        $providerRoles = array_map(static fn (Role $role): string => $role->value, Role::providerRoles());

        if (array_intersect($roles, $providerRoles) === []) {
            throw ValidationException::withMessages([
                'roles' => __('Deactivate this provider\'s schedules before removing their provider role.'),
            ]);
        }

        $scheduledDepartments = Service::query()
            ->whereIn('id', $staff->providerSchedules()->where('is_active', true)->select('service_id'))
            ->pluck('department_id')
            ->unique()
            ->all();

        if (array_diff($scheduledDepartments, $departmentIds) !== []) {
            throw ValidationException::withMessages([
                'department_ids' => __('Deactivate this provider\'s schedules in a department before removing that assignment.'),
            ]);
        }
    }
}
