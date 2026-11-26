<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\ClinicalPriority;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\Queue\QueueEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets clinical priority. Only clinicians with clinical.priority.assign in
 * the service's department may do this; a reason is required above routine.
 */
final class ChangeTicketPriority
{
    use OperatesQueue;

    public function __construct(private readonly QueueEventRecorder $events) {}

    public function handle(User $actor, QueueTicket $ticket, ClinicalPriority $priority, ?string $reason): QueueTicket
    {
        Gate::forUser($actor)->authorize('changePriority', $ticket);

        $reason = $reason === null ? null : trim($reason);

        Validator::make(['priority_reason' => $reason], [
            'priority_reason' => [$priority === ClinicalPriority::Routine ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
        ], ['priority_reason.required' => __('Give a reason for raising clinical priority.')])->validate();

        return DB::transaction(function () use ($actor, $ticket, $priority, $reason): QueueTicket {
            $locked = $this->lockTicket($ticket);

            if (! in_array($locked->status, [QueueTicketStatus::Waiting, QueueTicketStatus::Called, QueueTicketStatus::Skipped], true)) {
                throw ValidationException::withMessages(['ticket' => __('Priority can only change while the ticket is in the queue.')]);
            }

            $from = $locked->priority;
            $locked->priority = $priority;
            $locked->priority_reason = $priority === ClinicalPriority::Routine ? null : $reason;
            $locked->save();

            $this->events->record($locked, QueueEventType::PriorityChanged, $actor, null, $reason, [
                'from' => $from->value,
                'to' => $priority->value,
            ]);

            return $locked;
        });
    }
}
