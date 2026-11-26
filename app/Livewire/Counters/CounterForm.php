<?php

declare(strict_types=1);

namespace App\Livewire\Counters;

use App\Actions\Counters\SaveCounter;
use App\Models\Counter;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CounterForm extends Component
{
    #[Locked]
    public ?Counter $counter = null;

    public string $department_id = '';

    public string $code = '';

    public string $name = '';

    public string $location = '';

    public bool $is_active = true;

    /**
     * @var array<int, string>
     */
    public array $service_ids = [];

    public function mount(?Counter $counter = null): void
    {
        if ($counter === null || ! $counter->exists) {
            $this->authorize('create', Counter::class);
            $this->counter = null;

            return;
        }

        $this->authorize('update', $counter);
        $this->counter = $counter;
        $this->department_id = (string) $counter->department_id;
        $this->code = $counter->code;
        $this->name = $counter->name;
        $this->location = $counter->location ?? '';
        $this->is_active = $counter->is_active;
        $this->service_ids = $counter->services()->pluck('services.id')->map(fn (int $id): string => (string) $id)->values()->all();
    }

    public function updatedDepartmentId(): void
    {
        // Services belong to one department; selections from another no longer apply.
        $this->service_ids = [];
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        return Department::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->counter?->department_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function availableServices(): Collection
    {
        if ($this->department_id === '') {
            return new Collection;
        }

        return Service::query()
            ->where('department_id', (int) $this->department_id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', array_map('intval', $this->service_ids)))
            ->orderBy('queue_prefix')
            ->get();
    }

    public function save(SaveCounter $save): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $counter = $save->handle($actor, [
            'department_id' => $this->department_id === '' ? null : (int) $this->department_id,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location === '' ? null : $this->location,
            'is_active' => $this->is_active,
            'service_ids' => array_values(array_map('intval', $this->service_ids)),
        ], $this->counter);

        session()->flash('status', __('Saved counter :code.', ['code' => $counter->code]));
        $this->redirectRoute('counters.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.counters.form')
            ->title($this->counter === null ? __('Add counter') : __('Edit counter'));
    }
}
