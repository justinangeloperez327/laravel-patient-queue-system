<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Enums\DayOfWeek;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\User;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a weekly availability block.
 *
 * Rules: the provider is active, holds a provider role and is assigned to the
 * service's department; the service is active and accepts appointments; the
 * block is a whole number of slots; active blocks of one provider never
 * overlap on the same weekday (also an exclusion constraint).
 */
final class SaveProviderSchedule
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, User $provider, array $input, ?ProviderSchedule $schedule = null): ProviderSchedule
    {
        Gate::forUser($actor)->authorize($schedule === null ? 'create' : 'update', $schedule ?? ProviderSchedule::class);

        if ($schedule !== null && $schedule->provider_id !== $provider->id) {
            throw ValidationException::withMessages(['schedule' => __('This schedule belongs to another provider.')]);
        }

        /** @var array{service_id: int, day_of_week: int, start_time: string, end_time: string, slot_duration_minutes: int, is_active: bool} $data */
        $data = Validator::make($input, [
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'day_of_week' => ['required', 'integer', Rule::enum(DayOfWeek::class)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_duration_minutes' => ['required', 'integer', 'between:5,240'],
            'is_active' => ['required', 'boolean'],
        ])->after(function (\Illuminate\Validation\Validator $validator) use ($input): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $minutes = $this->minutesBetween((string) $input['start_time'], (string) $input['end_time']);

            if ($minutes % (int) $input['slot_duration_minutes'] !== 0) {
                $validator->errors()->add('slot_duration_minutes', __('The time range must divide into whole slots.'));
            }
        })->validate();

        $data['service_id'] = (int) $data['service_id'];
        $data['day_of_week'] = (int) $data['day_of_week'];

        return ConstraintViolation::guard(fn (): ProviderSchedule => DB::transaction(function () use ($provider, $data, $schedule): ProviderSchedule {
            // Serialise schedule changes per provider so the overlap check is reliable.
            User::query()->whereKey($provider->id)->lockForUpdate()->first();
            $provider->refresh();

            $service = Service::query()->whereKey($data['service_id'])->sharedLock()->firstOrFail();

            if ($data['is_active']) {
                $this->validateEligibility($provider, $service);
                $this->validateNoOverlap($provider, $data, $schedule);
            }

            $schedule ??= new ProviderSchedule;
            $schedule->provider_id = $provider->id;
            $schedule->fill($data)->save();

            return $schedule;
        }), [
            'provider_schedules_no_overlap' => ['start_time', __('This overlaps another active schedule for this provider.')],
        ]);
    }

    private function validateEligibility(User $provider, Service $service): void
    {
        if (! $provider->is_active || ! $provider->isProvider()) {
            throw ValidationException::withMessages([
                'provider' => __('Only active doctors, nurses or service staff can hold schedules.'),
            ]);
        }

        if (! $service->is_active || ! $service->allows_appointments) {
            throw ValidationException::withMessages([
                'service_id' => __('Choose an active service that accepts appointments.'),
            ]);
        }

        if (! $provider->isAssignedToDepartment($service->department_id)) {
            throw ValidationException::withMessages([
                'service_id' => __('The provider is not assigned to this service\'s department.'),
            ]);
        }
    }

    /**
     * @param  array{day_of_week: int, start_time: string, end_time: string}  $data
     */
    private function validateNoOverlap(User $provider, array $data, ?ProviderSchedule $schedule): void
    {
        $overlaps = ProviderSchedule::query()
            ->where('provider_id', $provider->id)
            ->where('day_of_week', $data['day_of_week'])
            ->where('is_active', true)
            ->when($schedule !== null, fn ($query) => $query->whereKeyNot($schedule?->id))
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_time' => __('This overlaps another active schedule for this provider.'),
            ]);
        }
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));

        return ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
    }
}
