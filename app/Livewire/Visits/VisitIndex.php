<?php

declare(strict_types=1);

namespace App\Livewire\Visits;

use App\Enums\VisitStatus;
use App\Livewire\Concerns\ClosesVisits;
use App\Models\Visit;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Visits')]
final class VisitIndex extends Component
{
    use ClosesVisits;
    use WithPagination;

    #[Url]
    public string $date = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Visit::class);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            $this->date = ClinicTime::today()->toDateString();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Visit>
     */
    #[Computed]
    public function visits(): LengthAwarePaginator
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1
            ? CarbonImmutable::parse($this->date, ClinicTime::timezone())
            : ClinicTime::today();

        return Visit::query()
            ->with(['patient', 'requestedService', 'appointment'])
            ->where('checked_in_at', '>=', $day->utc())
            ->where('checked_in_at', '<', $day->addDay()->utc())
            ->when(VisitStatus::tryFrom($this->status) !== null, fn ($query) => $query->where('status', $this->status))
            ->orderBy('checked_in_at')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.visits.index', ['statuses' => VisitStatus::cases()]);
    }
}
