<?php

declare(strict_types=1);

namespace App\Livewire\Clinical;

use App\Actions\Clinical\AmendClinicalNote;
use App\Enums\ClinicalNoteStatus;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\Clinical\ClinicalAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @property-read Collection<int, Visit> $visits
 *
 * The patient record. Reception sees demographics and visit history;
 * clinicians with an active care relationship also see signed notes,
 * amendments and vital signs. Every clinical read is audited.
 */
final class PatientRecord extends Component
{
    #[Locked]
    public Patient $patient;

    #[Locked]
    public bool $showClinical = false;

    #[Locked]
    public ?int $amendingNoteId = null;

    public string $amendment = '';

    public string $amendmentReason = '';

    public function mount(Patient $patient, ClinicalAccess $access): void
    {
        $this->authorize('viewRecord', $patient);
        $this->patient = $patient;

        if ($this->user()->can('viewClinicalHistory', $patient)) {
            $access->authorizeHistory($this->user(), $patient, 'patient_record');
            $this->showClinical = true;
        }
    }

    /**
     * @return Collection<int, Visit>
     */
    #[Computed]
    public function visits(): Collection
    {
        $relations = ['requestedService', 'tickets.service'];

        if ($this->showClinical) {
            $relations['clinicalNotes'] = fn ($query) => $query
                ->where(fn ($query) => $query->where('status', ClinicalNoteStatus::Signed->value)->orWhere('author_id', $this->user()->id))
                ->with(['author', 'amendments.author']);
            $relations[] = 'vitalSigns.recordedBy';
        }

        return $this->patient->visits()->with($relations)->orderByDesc('checked_in_at')->limit(25)->get();
    }

    public function startAmending(int $noteId): void
    {
        abort_unless($this->showClinical, 403);
        $this->amendingNoteId = $this->noteOfThisPatient($noteId)->id;
        $this->reset('amendment', 'amendmentReason');
    }

    public function amend(AmendClinicalNote $amend): void
    {
        abort_unless($this->showClinical && $this->amendingNoteId !== null, 403);

        $amend->handle($this->user(), $this->noteOfThisPatient($this->amendingNoteId), $this->amendment, $this->amendmentReason);
        $this->amendingNoteId = null;
        unset($this->visits);
        session()->flash('status', __('Amendment added and signed.'));
    }

    public function render(): View
    {
        return view('livewire.clinical.patient-record')->title($this->patient->fullName());
    }

    private function noteOfThisPatient(int $noteId): ClinicalNote
    {
        return ClinicalNote::query()
            ->whereKey($noteId)
            ->whereIn('visit_id', $this->patient->visits()->select('id'))
            ->firstOrFail();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
