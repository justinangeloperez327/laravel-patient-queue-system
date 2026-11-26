<?php

declare(strict_types=1);

namespace App\Livewire\Patients;

use App\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Patients')]
final class PatientIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $includeInactive = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Patient::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'includeInactive'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Patient>
     */
    #[Computed]
    public function patients(): LengthAwarePaginator
    {
        return Patient::query()
            ->search($this->search)
            ->when(! $this->includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(20);
    }

    public function render(): View
    {
        return view('livewire.patients.index');
    }
}
