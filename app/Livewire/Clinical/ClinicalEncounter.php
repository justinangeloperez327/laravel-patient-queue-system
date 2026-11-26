<?php

declare(strict_types=1);

namespace App\Livewire\Clinical;

use App\Actions\Clinical\RecordVitalSigns;
use App\Actions\Clinical\SaveClinicalNote;
use App\Actions\Clinical\SignClinicalNote;
use App\Enums\ClinicalNoteStatus;
use App\Enums\ClinicalNoteType;
use App\Enums\Permission;
use App\Models\ClinicalNote;
use App\Models\QueueTicket;
use App\Models\User;
use App\Models\VitalSign;
use App\Services\Clinical\ClinicalAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @property-read QueueTicket|null $ticket
 * @property-read Collection<int, ClinicalNote> $visitNotes
 * @property-read Collection<int, VitalSign> $visitVitals
 *
 * Documentation for the patient the clinician is serving right now. The
 * ticket is always re-derived from the clinician's open counter, never
 * taken from the client.
 */
abstract class ClinicalEncounter extends Component
{
    #[Locked]
    public ?int $ticketId = null;

    /**
     * @var array<string, string>
     */
    public array $vitals = [];

    /**
     * @var array<string, string>
     */
    public array $note = [];

    abstract protected function noteType(User $user): ClinicalNoteType;

    /**
     * @return view-string
     */
    abstract protected function viewName(): string;

    public function mount(ClinicalAccess $access): void
    {
        $user = $this->user();
        abort_unless($user->can(Permission::ViewClinicalHistory->value), 403);

        $ticket = $access->servingTicket($user);
        $this->ticketId = $ticket?->id;

        if ($ticket !== null) {
            // Opening the encounter shows this visit's clinical record: audited read.
            $access->authorizeHistory($user, $ticket->visit->patient, 'encounter');
            $this->loadDraft();
        }
    }

    #[Computed]
    public function ticket(): ?QueueTicket
    {
        $current = app(ClinicalAccess::class)->servingTicket($this->user());

        // If the clinician finished or transferred the patient elsewhere, stop showing them.
        return $current !== null && $current->id === $this->ticketId
            ? $current->load(['visit.patient', 'visit.requestedService', 'service'])
            : null;
    }

    /**
     * Signed notes of this visit, plus the clinician's own drafts.
     *
     * @return Collection<int, ClinicalNote>
     */
    #[Computed]
    public function visitNotes(): Collection
    {
        $ticket = $this->ticket;

        if ($ticket === null) {
            return new Collection;
        }

        return ClinicalNote::query()
            ->with(['author', 'amendments.author'])
            ->where('visit_id', $ticket->visit_id)
            ->where(fn ($query) => $query->where('status', ClinicalNoteStatus::Signed->value)->orWhere('author_id', $this->user()->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, VitalSign>
     */
    #[Computed]
    public function visitVitals(): Collection
    {
        $ticket = $this->ticket;

        return $ticket === null ? new Collection : VitalSign::query()->with('recordedBy')->where('visit_id', $ticket->visit_id)->orderBy('recorded_at')->get();
    }

    public function recordVitals(RecordVitalSigns $record): void
    {
        $record->handle($this->user(), $this->ticketOrFail(), $this->vitals);
        $this->vitals = [];
        unset($this->visitVitals);
        session()->flash('status', __('Vital signs recorded.'));
    }

    public function saveNote(SaveClinicalNote $save): void
    {
        $save->handle($this->user(), $this->ticketOrFail(), $this->noteType($this->user()), $this->note);
        unset($this->visitNotes);
        session()->flash('status', __('Draft saved.'));
    }

    public function signNote(SaveClinicalNote $save, SignClinicalNote $sign): void
    {
        $draft = $save->handle($this->user(), $this->ticketOrFail(), $this->noteType($this->user()), $this->note);
        $sign->handle($this->user(), $draft);
        $this->note = [];
        unset($this->visitNotes);
        session()->flash('status', __('Note signed. It can no longer be edited; add an amendment from the patient record if needed.'));
    }

    public function render(): View
    {
        return view($this->viewName(), [
            'noteType' => $this->noteType($this->user()),
            'canSign' => $this->user()->can($this->noteType($this->user())->signPermission()->value),
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function ticketOrFail(): QueueTicket
    {
        $ticket = $this->ticket;
        abort_if($ticket === null, 409, __('You are no longer serving this patient.'));

        return $ticket;
    }

    private function loadDraft(): void
    {
        $draft = ClinicalNote::query()
            ->where('queue_ticket_id', $this->ticketId)
            ->where('author_id', $this->user()->id)
            ->where('note_type', $this->noteType($this->user())->value)
            ->where('status', ClinicalNoteStatus::Draft->value)
            ->first();

        if ($draft !== null) {
            foreach (['chief_complaint', 'subjective', 'objective', 'assessment', 'plan', 'additional_notes'] as $field) {
                $this->note[$field] = (string) $draft->getAttribute($field);
            }
        }
    }
}
