<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueTicketStatus;
use App\Models\CounterSession;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stops staffing a counter. Not allowed while a ticket is called or being
 * served there.
 */
final class CloseCounterSession
{
    public function handle(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $session = CounterSession::query()->open()->where('user_id', $actor->id)->lockForUpdate()->first();

            if ($session === null) {
                return;
            }

            $active = QueueTicket::query()
                ->where('counter_id', $session->counter_id)
                ->inStatus([QueueTicketStatus::Called, QueueTicketStatus::Serving])
                ->first();

            if ($active !== null) {
                throw ValidationException::withMessages([
                    'counter' => __('Finish, skip or transfer :number before closing the counter.', ['number' => $active->ticket_number]),
                ]);
            }

            $session->closed_at = now();
            $session->save();
        });
    }
}
