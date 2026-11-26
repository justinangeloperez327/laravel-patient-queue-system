<?php

declare(strict_types=1);

namespace App\Livewire\Displays;

use App\Models\DisplayDevice;
use App\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, DisplayDevice> $devices
 * @property-read Collection<int, Service> $services
 */
#[Title('Displays')]
final class DisplayIndex extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', DisplayDevice::class);
    }

    /**
     * @return Collection<int, DisplayDevice>
     */
    #[Computed]
    public function devices(): Collection
    {
        return DisplayDevice::query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->get()->keyBy('id');
    }

    public function render(): View
    {
        return view('livewire.displays.index');
    }
}
