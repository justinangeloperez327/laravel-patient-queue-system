<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\Queue\QueueEventRecorder;
use App\Services\Queue\TicketIssuer;
use App\Support\ConstraintViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Closes the serving ticket as transferred and issues the next ticket for
 * another service in the same visit, in one transaction: either both
 * happen or neither does. Clinical priority carries over.
 */
final class TransferTicket
{
    use OperatesQueue;

    public function __construct(
        private readonly TicketIssuer $issuer,
        private readonly QueueEventRecorder $events,
    ) {}

    public function handle(User $actor, QueueTicket $ticket, int $targetServiceId, ?string $remarks = null, ?string $idempotencyKey = null): QueueTicket
    {
        Gate::forUser($actor)->authorize('transfer', $ticket);
        $key = $this->optionalKey($idempotencyKey);

        Validator::make(['service_id' => $targetServiceId, 'remarks' => $remarks], [
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'remarks' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return ConstraintViolation::guard(fn (): QueueTicket => DB::transaction(function () use ($actor, $ticket, $targetServiceId, $remarks, $key): QueueTicket {
            $locked = $this->lockTicket($ticket);

            if (($replayed = $this->events->replay($key)) !== null) {
                return $replayed;
            }

            $this->ensureAtActorsCounter($actor, $locked);
            $this->ensureTransition($locked, QueueTicketStatus::Transferred);

            if ($targetServiceId === $locked->service_id) {
                throw ValidationException::withMessages(['service_id' => __('Choose a different service to transfer to.')]);
            }

            $target = Service::query()->findOrFail($targetServiceId);
            /** @var Visit $visit */
            $visit = Visit::query()->whereKey($locked->visit_id)->lockForUpdate()->firstOrFail();

            $previous = $locked->status;
            $locked->status = QueueTicketStatus::Transferred;
            $locked->completed_at = now();
            $locked->served_by = $actor->id;
            $locked->save();

            $next = $this->issuer->issue(
                actor: $actor,
                visit: $visit,
                service: $target,
                priority: $locked->priority,
                priorityReason: $locked->priority_reason,
                transferredFrom: $locked,
                remarks: $remarks,
            );

            $this->events->record($locked, QueueEventType::Transferred, $actor, $previous, $remarks, [
                'to_service_id' => $target->id,
                'result_ticket_id' => $next->id,
            ], $key);

            return $next;
        }), [
            'queue_tickets_one_unresolved_per_visit_service' => ['service_id', __('The patient already has a ticket for that service.')],
            'queue_events_idempotency_key_unique' => ['ticket', __('This request was already processed.')],
        ]);
    }
}
