<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\ProviderScheduleException;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Removes an unavailability period that has not started yet. Past and
 * in-progress periods are kept as history.
 */
final class DeleteScheduleException
{
    public function handle(User $actor, ProviderScheduleException $exception): void
    {
        Gate::forUser($actor)->authorize('delete', $exception);

        $exception->delete();
    }
}
