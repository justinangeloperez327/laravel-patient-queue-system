<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Services')]
final class ServiceIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Service::class);
    }

    /**
     * @return LengthAwarePaginator<int, Service>
     */
    #[Computed]
    public function services(): LengthAwarePaginator
    {
        return Service::query()
            ->with(['department', 'triageService'])
            ->orderByDesc('is_active')
            ->orderBy('queue_prefix')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.services.index');
    }
}
