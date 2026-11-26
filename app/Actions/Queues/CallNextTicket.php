<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\Queue\QueueEventRecorder;
use App\Services\Queue\QueueOrdering;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Calls the next eligible ticket to the actor's counter.
 *
 * The counter row is locked, so one counter calls one ticket at a time; the
 * candidate is claimed with FOR UPDATE SKIP LOCKED, so concurrent counters
 * never claim the same ticket. A repeated request with the same idempotency
 * key returns the ticket from the first request (double clicks).
 */
final class CallNextTicket
{
    use OperatesQueue;

    public function __construct(
        private readonly QueueOrdering $ordering,
        private readonly QueueEventRecorder $events,
    ) {}

    public function handle(User $actor, Counter $counter, ?string $idempotencyKey = null): QueueTicket
    {
        Gate::forUser($actor)->authorize('operate', $counter);
        $key = $this->optionalKey($idempotencyKey);

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $counter, $key): QueueTicket {
            Counter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();

            if (($replayed = $this->events->replay($key)) !== null) {
                return $replayed;
            }

            $session = CounterSession::query()->open()->where('counter_id', $counter->id)->where('user_id', $actor->id)->first();

            if ($session === null) {
                throw ValidationException::withMessages(['counter' => __('Open this counter before calling patients.')]);
            }

            $current = QueueTicket::query()
                ->where('counter_id', $counter->id)
                ->inStatus([QueueTicketStatus::Called, QueueTicketStatus::Serving])
                ->first();

            if ($current !== null) {
                throw ValidationException::withMessages([
                    'counter' => __('Finish, skip or transfer :number before calling the next patient.', ['number' => $current->ticket_number]),
                ]);
            }

            $serviceIds = $counter->services()->where('services.is_active', true)->pluck('services.id')
                ->map(fn (mixed $id): int => (int) $id)->all();

            /** @var QueueTicket|null $ticket */
            $ticket = $this->ordering->eligible(array_values($serviceIds), $actor->id)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($ticket === null) {
                throw ValidationException::withMessages(['queue' => __('Nobody is waiting for this counter.')]);
            }

            $previous = $ticket->status;
            $ticket->status = QueueTicketStatus::Called;
            $ticket->counter_id = $counter->id;
            $ticket->called_at = now();
            $ticket->save();

            $this->events->record($ticket, QueueEventType::Called, $actor, $previous, idempotencyKey: $key);

            return $ticket;
        }), [
            'queue_tickets_one_active_per_counter' => ['counter', __('This counter is already calling a patient.')],
            'queue_events_idempotency_key_unique' => ['counter', __('This request was already processed.')],
        ]);
    }
}
