<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\ClinicalPriority;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\SchedulingPreference;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Issues a ticket for a visit. Must run inside the caller's transaction so
 * that issuance is atomic with check-in or transfer. A visit holds at most
 * one unresolved ticket at a time; the database additionally enforces one
 * unresolved ticket per visit and service.
 */
final class TicketIssuer
{
    public function __construct(
        private readonly QueueSequenceAllocator $sequences,
        private readonly QueueEventRecorder $events,
    ) {}

    public function issue(
        User $actor,
        Visit $visit,
        Service $service,
        SchedulingPreference $preference = SchedulingPreference::Standard,
        ?CarbonImmutable $eligibleAt = null,
        ?int $assignedProviderId = null,
        ClinicalPriority $priority = ClinicalPriority::Routine,
        ?string $priorityReason = null,
        ?QueueTicket $transferredFrom = null,
        ?string $remarks = null,
    ): QueueTicket {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Tickets must be issued inside a database transaction.');
        }

        if (! $visit->status->isOpen()) {
            throw ValidationException::withMessages(['visit' => __('Tickets can only be issued for an open visit.')]);
        }

        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => __('This service is not active.')]);
        }

        $unresolved = QueueTicket::query()
            ->where('visit_id', $visit->id)
            ->inStatus(QueueTicketStatus::unresolved())
            ->first();

        if ($unresolved !== null) {
            throw ValidationException::withMessages([
                'visit' => __('This visit already has ticket :number in progress.', ['number' => $unresolved->ticket_number]),
            ]);
        }

        $now = CarbonImmutable::now();
        $queueDate = ClinicTime::today();
        $sequence = $this->sequences->next($service, $queueDate);

        $ticket = new QueueTicket;
        $ticket->visit_id = $visit->id;
        $ticket->service_id = $service->id;
        $ticket->assigned_provider_id = $assignedProviderId;
        $ticket->transferred_from_id = $transferredFrom?->id;
        $ticket->ticket_number = QueueSequenceAllocator::format($service->queue_prefix, $sequence);
        $ticket->sequence_number = $sequence;
        $ticket->queue_date = $queueDate;
        $ticket->priority = $priority;
        $ticket->priority_reason = $priority === ClinicalPriority::Routine ? null : $priorityReason;
        $ticket->scheduling_preference = $preference;
        $ticket->status = QueueTicketStatus::Waiting;
        $ticket->issued_by = $actor->id;
        $ticket->queued_at = $now;
        $ticket->eligible_at = $eligibleAt !== null && $eligibleAt->gt($now) ? $eligibleAt : $now;
        $ticket->save();

        $this->events->record($ticket, QueueEventType::Issued, $actor, null, $remarks, array_filter([
            'transferred_from_ticket_id' => $transferredFrom?->id,
        ]));

        return $ticket;
    }
}
