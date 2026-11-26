<?php

declare(strict_types=1);

namespace App\Livewire\Clinical;

use App\Enums\ClinicalNoteType;
use App\Enums\Permission;
use App\Models\User;
use Livewire\Attributes\Title;

/**
 * Consultation: vital signs and a SOAP note (doctors) or nursing note
 * (nurses). Completing or transferring the patient happens at the counter.
 */
#[Title('Consultation')]
final class ConsultationEncounter extends ClinicalEncounter
{
    protected function noteType(User $user): ClinicalNoteType
    {
        return $user->can(Permission::WriteSoapNotes->value) ? ClinicalNoteType::Soap : ClinicalNoteType::Nursing;
    }

    /**
     * @return view-string
     */
    protected function viewName(): string
    {
        return 'livewire.clinical.consultation';
    }
}
