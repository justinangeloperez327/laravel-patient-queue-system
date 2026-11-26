<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Staff')]
final class StaffIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function staff(): LengthAwarePaginator
    {
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($this->search))).'%';

        return User::query()
            ->with(['roles', 'departments'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(
                fn ($query) => $query->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]),
            ))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.staff.index');
    }
}
