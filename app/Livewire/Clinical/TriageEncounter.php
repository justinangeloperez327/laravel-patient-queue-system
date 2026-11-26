<?php

declare(strict_types=1);

namespace App\Livewire\Clinical;

use App\Actions\Queues\TransferTicket;
use App\Enums\ClinicalNoteType;
use App\Enums\ClinicalPriority;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Clinical\ClinicalAccess;
use Livewire\Attributes\Title;

/**
 * Triage: vital signs, a triage assessment, clinical priority and routing
 * on to the requested service.
 */
#[Title('Triage')]
final class TriageEncounter extends ClinicalEncounter
{
    public int $priority = 0;

    public string $priorityReason = '';

    public function mount(ClinicalAccess $access): void
    {
        abort_unless($this->user()->can(Permission::PerformTriage->value), 403);
        parent::mount($access);
    }

    protected function noteType(User $user): ClinicalNoteType
    {
        return ClinicalNoteType::Triage;
    }

    /**
     * @return view-string
     */
    protected function viewName(): string
    {
        return 'livewire.clinical.triage';
    }

    public function sendOn(TransferTicket $transfer): void
    {
        $ticket = $this->ticketOrFail();
        $next = $transfer->handle(
            $this->user(),
            $ticket,
            $ticket->visit->requested_service_id,
            __('Triage completed'),
            null,
            ClinicalPriority::from($this->priority),
            $this->priorityReason,
        );

        session()->flash('status', __('Triage complete. :from → :to.', ['from' => $ticket->ticket_number, 'to' => $next->ticket_number]));
        $this->redirectRoute('workspace', navigate: true);
    }
}
