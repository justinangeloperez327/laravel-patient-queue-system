<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * Patient lookup for reception forms.
 */
trait SearchesPatients
{
    public string $patientSearch = '';

    public ?int $patient_id = null;

    /**
     * @return Collection<int, Patient>
     */
    #[Computed]
    public function patientResults(): Collection
    {
        if (mb_strlen(trim($this->patientSearch)) < 2) {
            return new Collection;
        }

        return Patient::query()
            ->where('is_active', true)
            ->search($this->patientSearch)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function selectedPatient(): ?Patient
    {
        return $this->patient_id === null ? null : Patient::query()->find($this->patient_id);
    }

    public function selectPatient(int $patientId): void
    {
        $this->authorize('viewAny', Patient::class);
        $this->patient_id = Patient::query()->where('is_active', true)->findOrFail($patientId)->id;
        $this->patientSearch = '';
        $this->resetErrorBag('patient_id');
    }

    public function clearPatient(): void
    {
        $this->patient_id = null;
    }
}
