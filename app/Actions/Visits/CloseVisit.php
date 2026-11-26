<?php

declare(strict_types=1);

namespace App\Actions\Visits;

use App\Enums\AppointmentStatus;
use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\Visit;
use App\Services\Queue\QueueEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Records the disposition of an open visit that will not complete
 * normally (docs/WORKFLOW.md: skipped or cancelled tickets require an
 * explicit disposition):
 *
 * - cancelled: before any service started (e.g. registered in error);
 * - left without service: the patient left, before or between services.
 *
 * Not allowed while a ticket is called or being served. Waiting and skipped
 * tickets are cancelled with a queue event. Cancelling an appointment visit
 * also cancels the appointment; a patient who left keeps the appointment as
 * checked in (they attended).
 */
final class CloseVisit
{
    public function __construct(private readonly QueueEventRecorder $events) {}

    public function handle(User $actor, Visit $visit, VisitStatus $outcome, string $reason): Visit
    {
        Gate::forUser($actor)->authorize('update', $visit);

        if (! in_array($outcome, [VisitStatus::Cancelled, VisitStatus::LeftWithoutService], true)) {
            throw new \InvalidArgumentException('Outcome must be cancelled or left without service.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($actor, $visit, $outcome, $data): Visit {
            /** @var Visit $locked */
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($outcome)) {
                throw ValidationException::withMessages([
                    'status' => $outcome === VisitStatus::Cancelled && $locked->status === VisitStatus::InProgress
                        ? __('Service has started for this visit; record that the patient left instead.')
                        : __('This visit is already closed.'),
                ]);
            }

            $tickets = QueueTicket::query()
                ->where('visit_id', $locked->id)
                ->inStatus(QueueTicketStatus::unresolved())
                ->lockForUpdate()
                ->get();

            $atCounter = $tickets->first(fn (QueueTicket $ticket): bool => in_array($ticket->status, [QueueTicketStatus::Called, QueueTicketStatus::Serving], true));

            if ($atCounter !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Ticket :number is at a counter. Finish or skip it there first.', ['number' => $atCounter->ticket_number]),
                ]);
            }

            foreach ($tickets as $ticket) {
                $previous = $ticket->status;
                $ticket->status = QueueTicketStatus::Cancelled;
                $ticket->save();
                $this->events->record($ticket, QueueEventType::Cancelled, $actor, $previous, __('Visit closed: :reason', ['reason' => $data['reason']]));
            }

            $locked->status = $outcome;
            $locked->cancelled_at = now();
            $locked->save();

            if ($outcome === VisitStatus::Cancelled && $locked->appointment_id !== null) {
                /** @var Appointment $appointment */
                $appointment = Appointment::query()->whereKey($locked->appointment_id)->lockForUpdate()->firstOrFail();
                $appointment->status = AppointmentStatus::Cancelled;
                $appointment->cancelled_at = now();
                $appointment->cancellation_reason = __('Visit cancelled: :reason', ['reason' => $data['reason']]);
                $appointment->save();
            }

            AuditLog::record($actor, $outcome === VisitStatus::Cancelled ? 'visit.cancelled' : 'visit.left_without_service', $locked, [
                'appointment_id' => $locked->appointment_id,
                'cancelled_ticket_ids' => $tickets->pluck('id')->all(),
            ]);

            return $locked;
        });
    }
}
