<?php

declare(strict_types=1);

namespace App\Livewire\Departments;

use App\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Departments')]
final class DepartmentIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    /**
     * @return LengthAwarePaginator<int, Department>
     */
    #[Computed]
    public function departments(): LengthAwarePaginator
    {
        return Department::query()
            ->withCount(['services', 'counters'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.departments.index');
    }
}
