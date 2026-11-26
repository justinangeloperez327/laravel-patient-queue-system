<?php

declare(strict_types=1);

namespace App\Actions\Queues;

use App\Enums\AppointmentStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;

/**
 * Finishes service. When no other ticket of the visit is unresolved, the
 * visit is completed, and so is its appointment (docs/WORKFLOW.md).
 */
final class CompleteTicket extends TicketAtCounterAction
{
    protected function ability(): string
    {
        return 'complete';
    }

    protected function apply(User $actor, QueueTicket $ticket): QueueEventType
    {
        $this->moveTo($ticket, QueueTicketStatus::Completed);
        $ticket->completed_at = now();
        $ticket->served_by = $actor->id;

        /** @var Visit $visit */
        $visit = Visit::query()->whereKey($ticket->visit_id)->lockForUpdate()->firstOrFail();

        $othersUnresolved = QueueTicket::query()
            ->where('visit_id', $visit->id)
            ->whereKeyNot($ticket->id)
            ->inStatus(QueueTicketStatus::unresolved())
            ->exists();

        if (! $othersUnresolved && $visit->status->canTransitionTo(VisitStatus::Completed)) {
            $visit->status = VisitStatus::Completed;
            $visit->completed_at = now();
            $visit->save();

            if ($visit->appointment_id !== null) {
                /** @var Appointment $appointment */
                $appointment = Appointment::query()->whereKey($visit->appointment_id)->lockForUpdate()->firstOrFail();

                if ($appointment->status->canTransitionTo(AppointmentStatus::Completed)) {
                    $appointment->status = AppointmentStatus::Completed;
                    $appointment->save();
                }
            }
        }

        return QueueEventType::Completed;
    }
}
