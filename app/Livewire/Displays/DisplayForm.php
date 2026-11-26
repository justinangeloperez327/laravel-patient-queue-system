<?php

declare(strict_types=1);

namespace App\Livewire\Displays;

use App\Actions\Displays\SaveDisplayDevice;
use App\Models\DisplayDevice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @property-read Collection<int, Service> $serviceOptions
 */
final class DisplayForm extends Component
{
    #[Locked]
    public ?DisplayDevice $device = null;

    public string $name = '';

    public string $title = '';

    /**
     * @var array<int, string>
     */
    public array $service_ids = [];

    public string $voice_lang = 'en-US';

    public bool $is_active = true;

    /**
     * Shown exactly once after creation or rotation.
     */
    #[Locked]
    public ?string $newKey = null;

    public function mount(?DisplayDevice $device = null): void
    {
        if ($device === null || ! $device->exists) {
            $this->authorize('create', DisplayDevice::class);
            $this->device = null;

            return;
        }

        $this->authorize('update', $device);
        $this->device = $device;
        $this->name = $device->name;
        $this->title = $device->configuration['title'] ?? '';
        $this->service_ids = array_map('strval', $device->serviceIds());
        $this->voice_lang = $device->voiceLang();
        $this->is_active = $device->is_active;
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function serviceOptions(): Collection
    {
        return Service::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_map('intval', $this->service_ids)))->orderBy('queue_prefix')->get();
    }

    public function save(SaveDisplayDevice $save): void
    {
        [$device, $key] = $save->handle($this->actor(), [
            'name' => $this->name,
            'title' => $this->title,
            'service_ids' => array_values(array_map('intval', $this->service_ids)),
            'voice_lang' => $this->voice_lang,
            'is_active' => $this->is_active,
        ], $this->device);

        $this->device = $device;

        if ($key !== null) {
            $this->newKey = $key;

            return;
        }

        session()->flash('status', __('Saved display :name.', ['name' => $device->name]));
        $this->redirectRoute('displays.index', navigate: true);
    }

    public function rotateKey(SaveDisplayDevice $save): void
    {
        abort_if($this->device === null, 404);
        $this->newKey = $save->rotateKey($this->actor(), $this->device);
    }

    public function render(): View
    {
        return view('livewire.displays.form')->title($this->device === null ? __('Add display') : __('Edit display'));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
