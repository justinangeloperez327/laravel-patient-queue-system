<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\ProviderScheduleException;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Records a period of provider unavailability. Inputs are clinic-local
 * date-times; they are stored as UTC instants.
 */
final class CreateScheduleException
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, User $provider, array $input): ProviderScheduleException
    {
        Gate::forUser($actor)->authorize('create', ProviderScheduleException::class);

        /** @var array{starts_at: string, ends_at: string, reason: string} $data */
        $data = Validator::make($input, [
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();

        $startsAt = ClinicTime::localToUtc($data['starts_at']);
        $endsAt = ClinicTime::localToUtc($data['ends_at']);

        return ConstraintViolation::guard(fn (): ProviderScheduleException => DB::transaction(function () use ($provider, $startsAt, $endsAt, $data): ProviderScheduleException {
            User::query()->whereKey($provider->id)->lockForUpdate()->first();

            $overlaps = ProviderScheduleException::query()
                ->where('provider_id', $provider->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages(['starts_at' => __('This overlaps an existing unavailability period.')]);
            }

            $exception = new ProviderScheduleException([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => trim($data['reason']),
            ]);
            $exception->provider_id = $provider->id;
            $exception->save();

            return $exception;
        }), [
            'provider_schedule_exceptions_no_overlap' => ['starts_at', __('This overlaps an existing unavailability period.')],
        ]);
    }
}
