<?php

declare(strict_types=1);

namespace App\Actions\Departments;

use App\Models\Department;
use App\Models\User;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a department. Departments are never deleted.
 */
final class SaveDepartment
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Department $department = null): Department
    {
        Gate::forUser($actor)->authorize(
            $department === null ? 'create' : 'update',
            $department ?? Department::class,
        );

        $input['code'] = is_string($input['code'] ?? null) ? mb_strtoupper(trim($input['code'])) : ($input['code'] ?? null);

        /** @var array{code: string, name: string, description: string|null, is_active: bool} $data */
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('departments', 'code')->ignore($department?->id)],
            'name' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) use ($department): void {
                $exists = Department::query()
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($department !== null, fn ($query) => $query->whereKeyNot($department?->id))
                    ->exists();

                if ($exists) {
                    $fail(__('A department with this name already exists.'));
                }
            }],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.regex' => __('Use uppercase letters, digits, hyphens or underscores.'),
        ])->validate();

        $data['name'] = trim($data['name']);

        return ConstraintViolation::guard(fn (): Department => DB::transaction(function () use ($data, $department): Department {
            $department ??= new Department;

            if ($department->exists && $department->is_active && ! $data['is_active']) {
                $this->ensureCanDeactivate($department);
            }

            $department->fill($data)->save();

            return $department;
        }), [
            'departments_code_unique' => ['code', __('This code is already in use.')],
            'departments_name_lower_unique' => ['name', __('A department with this name already exists.')],
        ]);
    }

    private function ensureCanDeactivate(Department $department): void
    {
        Department::query()->whereKey($department->id)->lockForUpdate()->first();

        if ($department->services()->active()->exists() || $department->counters()->active()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => __('Deactivate this department\'s services and counters first.'),
            ]);
        }
    }
}
