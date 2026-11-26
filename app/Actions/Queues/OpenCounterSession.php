<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\User;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts staffing a counter. One open session per counter and per user.
 */
final class OpenCounterSession
{
    public function handle(User $actor, Counter $counter): CounterSession
    {
        Gate::forUser($actor)->authorize('operate', $counter);

        return ConstraintViolation::guard(fn (): CounterSession => DB::transaction(function () use ($actor, $counter): CounterSession {
            Counter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();

            $mine = CounterSession::query()->open()->where('user_id', $actor->id)->with('counter')->first();

            if ($mine !== null) {
                if ($mine->counter_id === $counter->id) {
                    return $mine; // Already open here: idempotent.
                }

                throw ValidationException::withMessages([
                    'counter' => __('You are signed in at :counter. Close that counter first.', ['counter' => $mine->counter->name]),
                ]);
            }

            $other = CounterSession::query()->open()->where('counter_id', $counter->id)->with('user')->first();

            if ($other !== null) {
                throw ValidationException::withMessages([
                    'counter' => __(':counter is staffed by :name.', ['counter' => $counter->name, 'name' => $other->user->name]),
                ]);
            }

            $session = new CounterSession;
            $session->counter_id = $counter->id;
            $session->user_id = $actor->id;
            $session->opened_at = now();
            $session->save();

            return $session;
        }), [
            'counter_sessions_one_open_per_counter' => ['counter', __('This counter was just opened by someone else.')],
            'counter_sessions_one_open_per_user' => ['counter', __('You already have a counter open.')],
        ]);
    }
}
