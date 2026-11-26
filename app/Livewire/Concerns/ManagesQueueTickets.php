<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Queues\CancelTicket;
use App\Actions\Queues\ChangeTicketPriority;
use App\Actions\Queues\RequeueTicket;
use App\Enums\ClinicalPriority;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Ticket actions available away from the counter: requeue, cancel with a
 * reason and clinical priority. Each submit carries an idempotency key so
 * a double click is applied once.
 */
trait ManagesQueueTickets
{
    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?int $editingTicketId = null;

    #[Locked]
    public string $editingMode = '';

    public string $ticketReason = '';

    public int $ticketPriority = 0;

    public function bootManagesQueueTickets(): void
    {
        if ($this->requestKey === '') {
            $this->requestKey = (string) Str::uuid();
        }
    }

    protected function rotateRequestKey(): void
    {
        $this->requestKey = (string) Str::uuid();
    }

    public function editTicket(int $ticketId, string $mode): void
    {
        $ticket = QueueTicket::query()->findOrFail($ticketId);
        $this->editingTicketId = $ticket->id;
        $this->editingMode = in_array($mode, ['cancel', 'priority'], true) ? $mode : 'cancel';
        $this->ticketReason = $mode === 'priority' ? (string) $ticket->priority_reason : '';
        $this->ticketPriority = $ticket->priority->value;
        $this->resetErrorBag();
    }

    public function stopEditingTicket(): void
    {
        $this->editingTicketId = null;
        $this->editingMode = '';
    }

    public function requeue(int $ticketId, RequeueTicket $requeue): void
    {
        $ticket = $requeue->handle($this->queueActor(), QueueTicket::query()->findOrFail($ticketId), $this->requestKey.'-rq-'.$ticketId);
        $this->rotateRequestKey();
        $this->queueStatus(__(':number is back in the queue.', ['number' => $ticket->ticket_number]));
    }

    public function saveTicketEdit(CancelTicket $cancel, ChangeTicketPriority $changePriority): void
    {
        $ticket = QueueTicket::query()->findOrFail($this->editingTicketId);

        if ($this->editingMode === 'priority') {
            $ticket = $changePriority->handle($this->queueActor(), $ticket, ClinicalPriority::from($this->ticketPriority), $this->ticketReason);
            $this->queueStatus(__(':number priority: :priority.', ['number' => $ticket->ticket_number, 'priority' => strtolower($ticket->priority->label())]));
        } else {
            $ticket = $cancel->handle($this->queueActor(), $ticket, $this->ticketReason, $this->requestKey.'-cx-'.$ticket->id);
            $this->rotateRequestKey();
            $this->queueStatus(__(':number cancelled.', ['number' => $ticket->ticket_number]));
        }

        $this->stopEditingTicket();
    }

    protected function queueActor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function queueStatus(string $message): void
    {
        session()->flash('status', $message);
    }
}
