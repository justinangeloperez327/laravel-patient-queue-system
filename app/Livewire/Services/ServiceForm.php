<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Actions\Services\SaveService;
use App\Enums\TriageMode;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ServiceForm extends Component
{
    #[Locked]
    public ?Service $service = null;

    public string $department_id = '';

    public string $name = '';

    public string $queue_prefix = '';

    public string $triage_mode = 'none';

    public string $triage_service_id = '';

    public bool $allows_appointments = false;

    public int $estimated_duration_minutes = 15;

    public bool $is_active = true;

    public function mount(?Service $service = null): void
    {
        if ($service === null || ! $service->exists) {
            $this->authorize('create', Service::class);
            $this->service = null;

            return;
        }

        $this->authorize('update', $service);
        $this->service = $service;
        $this->department_id = (string) $service->department_id;
        $this->name = $service->name;
        $this->queue_prefix = $service->queue_prefix;
        $this->triage_mode = $service->triage_mode->value;
        $this->triage_service_id = (string) ($service->triage_service_id ?? '');
        $this->allows_appointments = $service->allows_appointments;
        $this->estimated_duration_minutes = $service->estimated_duration_minutes;
        $this->is_active = $service->is_active;
    }

    /**
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        return Department::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->service?->department_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Active services without triage of their own can act as triage services.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function triageCandidates(): Collection
    {
        return Service::query()
            ->active()
            ->where('triage_mode', TriageMode::None->value)
            ->when($this->service !== null, fn ($query) => $query->whereKeyNot($this->service?->id))
            ->with('department')
            ->orderBy('name')
            ->get();
    }

    public function save(SaveService $save): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $service = $save->handle($actor, [
            'department_id' => $this->department_id === '' ? null : (int) $this->department_id,
            'name' => $this->name,
            'queue_prefix' => $this->queue_prefix,
            'triage_mode' => $this->triage_mode,
            'triage_service_id' => $this->triage_service_id === '' ? null : (int) $this->triage_service_id,
            'allows_appointments' => $this->allows_appointments,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'is_active' => $this->is_active,
        ], $this->service);

        session()->flash('status', __('Saved service :name.', ['name' => $service->name]));
        $this->redirectRoute('services.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.services.form', ['triageModes' => TriageMode::cases()])
            ->title($this->service === null ? __('Add service') : __('Edit service'));
    }
}
