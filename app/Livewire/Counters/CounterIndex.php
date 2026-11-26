<?php

declare(strict_types=1);

namespace App\Livewire\Counters;

use App\Models\Counter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Counters')]
final class CounterIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Counter::class);
    }

    /**
     * @return LengthAwarePaginator<int, Counter>
     */
    #[Computed]
    public function counters(): LengthAwarePaginator
    {
        return Counter::query()
            ->with(['department', 'services'])
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.counters.index');
    }
}
