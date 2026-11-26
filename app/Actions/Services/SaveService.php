<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Enums\TriageMode;
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
 * Creates or updates a service, enforcing triage routing rules:
 *
 * - a service with optional/required triage names exactly one triage service;
 * - the triage service must be active and itself have no triage (no chains);
 * - a service other services route through cannot gain triage or be deactivated
 *   while active services still route through it.
 */
final class SaveService
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Service $service = null): Service
    {
        Gate::forUser($actor)->authorize($service === null ? 'create' : 'update', $service ?? Service::class);

        $input['queue_prefix'] = is_string($input['queue_prefix'] ?? null)
            ? mb_strtoupper(trim($input['queue_prefix']))
            : ($input['queue_prefix'] ?? null);

        if (($input['triage_mode'] ?? null) === TriageMode::None->value) {
            $input['triage_service_id'] = null;
        }

        /** @var array{department_id: int, name: string, queue_prefix: string, triage_mode: string, triage_service_id: int|null, allows_appointments: bool, estimated_duration_minutes: int, is_active: bool} $data */
        $data = Validator::make($input, [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'queue_prefix' => ['required', 'string', 'regex:/^[A-Z]{1,3}$/', Rule::unique('services', 'queue_prefix')->ignore($service?->id)],
            'triage_mode' => ['required', Rule::enum(TriageMode::class)],
            'triage_service_id' => [
                Rule::requiredIf(fn (): bool => ($input['triage_mode'] ?? null) !== TriageMode::None->value),
                'nullable',
                'integer',
                Rule::exists('services', 'id'),
            ],
            'allows_appointments' => ['required', 'boolean'],
            'estimated_duration_minutes' => ['required', 'integer', 'between:1,480'],
            'is_active' => ['required', 'boolean'],
        ], [
            'queue_prefix.regex' => __('Use one to three uppercase letters.'),
            'triage_service_id.required' => __('Choose the triage service patients are routed through.'),
        ])->validate();

        $data['name'] = trim($data['name']);
        $data['department_id'] = (int) $data['department_id'];
        $data['triage_service_id'] = $data['triage_service_id'] === null ? null : (int) $data['triage_service_id'];

        return ConstraintViolation::guard(fn (): Service => DB::transaction(function () use ($data, $service): Service {
            if ($service !== null) {
                Service::query()->whereKey($service->id)->lockForUpdate()->first();
                $service->refresh();
            }

            $this->validateDepartment($data, $service);
            $this->validateName($data, $service);
            $this->validateTriageTarget($data, $service);

            if ($service !== null) {
                $this->validateAgainstDependents($data, $service);
            }

            $service ??= new Service;
            $service->fill($data)->save();

            return $service;
        }), [
            'services_queue_prefix_unique' => ['queue_prefix', __('This queue prefix is already in use.')],
            'services_department_name_lower_unique' => ['name', __('This department already has a service with this name.')],
        ]);
    }

    /**
     * @param  array{department_id: int, is_active: bool}  $data
     */
    private function validateDepartment(array $data, ?Service $service): void
    {
        $department = Department::query()->whereKey($data['department_id'])->sharedLock()->firstOrFail();
        $changing = $service === null || $service->department_id !== $department->id;

        if ($changing && ! $department->is_active) {
            throw ValidationException::withMessages(['department_id' => __('Choose an active department.')]);
        }

        if ($service !== null && $service->department_id !== $department->id) {
            if ($service->counters()->exists()) {
                throw ValidationException::withMessages([
                    'department_id' => __('Remove this service from its counters before moving it to another department.'),
                ]);
            }

            if ($service->providerSchedules()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'department_id' => __('Deactivate this service\'s provider schedules before moving it to another department.'),
                ]);
            }
        }
    }

    /**
     * @param  array{department_id: int, name: string}  $data
     */
    private function validateName(array $data, ?Service $service): void
    {
        $exists = Service::query()
            ->where('department_id', $data['department_id'])
            ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
            ->when($service !== null, fn ($query) => $query->whereKeyNot($service?->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('This department already has a service with this name.')]);
        }
    }

    /**
     * @param  array{triage_mode: string, triage_service_id: int|null}  $data
     */
    private function validateTriageTarget(array $data, ?Service $service): void
    {
        if ($data['triage_service_id'] === null) {
            return;
        }

        if ($service !== null && $data['triage_service_id'] === $service->id) {
            throw ValidationException::withMessages(['triage_service_id' => __('A service cannot be its own triage service.')]);
        }

        // Shared lock: blocks a concurrent change that would give the target triage of its own.
        $target = Service::query()->whereKey($data['triage_service_id'])->sharedLock()->firstOrFail();

        if (! $target->is_active) {
            throw ValidationException::withMessages(['triage_service_id' => __('The triage service must be active.')]);
        }

        if ($target->triage_mode !== TriageMode::None) {
            throw ValidationException::withMessages([
                'triage_service_id' => __('The triage service must not route through triage itself.'),
            ]);
        }
    }

    /**
     * @param  array{triage_mode: string, allows_appointments: bool, is_active: bool}  $data
     */
    private function validateAgainstDependents(array $data, Service $service): void
    {
        $routed = $service->routedServices();

        if ($data['triage_mode'] !== TriageMode::None->value && $routed->exists()) {
            throw ValidationException::withMessages([
                'triage_mode' => __('Other services route through this service for triage, so it cannot require triage itself.'),
            ]);
        }

        if (! $data['is_active'] && $service->routedServices()->active()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => __('Active services route through this service for triage. Change their triage routing first.'),
            ]);
        }

        if (! $data['allows_appointments'] && $service->providerSchedules()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'allows_appointments' => __('Deactivate this service\'s provider schedules before disabling appointments.'),
            ]);
        }
    }
}
