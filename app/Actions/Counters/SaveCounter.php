<?php

declare(strict_types=1);

namespace App\Actions\Counters;

use App\Models\Counter;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a counter and the services it serves. A counter only
 * serves services of its own department (also enforced by composite foreign
 * keys on counter_services).
 */
final class SaveCounter
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Counter $counter = null): Counter
    {
        Gate::forUser($actor)->authorize($counter === null ? 'create' : 'update', $counter ?? Counter::class);

        $input['code'] = is_string($input['code'] ?? null) ? mb_strtoupper(trim($input['code'])) : ($input['code'] ?? null);

        /** @var array{department_id: int, code: string, name: string, location: string|null, is_active: bool, service_ids: list<int>} $data */
        $data = Validator::make($input, [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('counters', 'code')->ignore($counter?->id)],
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', 'distinct'],
        ], [
            'code.regex' => __('Use uppercase letters, digits, hyphens or underscores.'),
        ])->validate();

        $serviceIds = array_values(array_unique(array_map('intval', $data['service_ids'])));
        unset($data['service_ids']);
        $data['department_id'] = (int) $data['department_id'];

        return ConstraintViolation::guard(fn (): Counter => DB::transaction(function () use ($data, $serviceIds, $counter): Counter {
            $department = Department::query()->whereKey($data['department_id'])->sharedLock()->firstOrFail();

            if (($counter === null || $counter->department_id !== $department->id) && ! $department->is_active) {
                throw ValidationException::withMessages(['department_id' => __('Choose an active department.')]);
            }

            $this->validateServices($serviceIds, $department, $counter);

            $counter ??= new Counter;

            if ($counter->exists && $counter->department_id !== $department->id) {
                // Pivot rows carry the old department; clear them before moving.
                $counter->services()->detach();
            }

            $counter->fill($data)->save();
            $counter->services()->sync(
                collect($serviceIds)->mapWithKeys(fn (int $id): array => [$id => ['department_id' => $department->id]])->all(),
            );

            return $counter;
        }), [
            'counters_code_unique' => ['code', __('This code is already in use.')],
        ]);
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function validateServices(array $serviceIds, Department $department, ?Counter $counter): void
    {
        if ($serviceIds === []) {
            return;
        }

        $services = Service::query()->whereKey($serviceIds)->sharedLock()->get();
        $alreadyAttached = $counter?->services()->pluck('services.id')->all() ?? [];

        foreach ($serviceIds as $index => $id) {
            $service = $services->firstWhere('id', $id);

            if (! $service instanceof Service || $service->department_id !== $department->id) {
                throw ValidationException::withMessages([
                    "service_ids.{$index}" => __('Counters can only serve services of their own department.'),
                    'service_ids' => __('Counters can only serve services of their own department.'),
                ]);
            }

            if (! $service->is_active && ! in_array($id, $alreadyAttached, true)) {
                throw ValidationException::withMessages([
                    'service_ids' => __('Only active services can be added to a counter.'),
                ]);
            }
        }
    }
}
