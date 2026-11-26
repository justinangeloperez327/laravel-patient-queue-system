<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Schedules\SaveProviderSchedule;
use App\Enums\DayOfWeek;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Weekly availability blocks of one provider.
 */
final class ProviderSchedules extends Component
{
    #[Locked]
    public User $provider;

    #[Locked]
    public ?int $editingId = null;

    public string $service_id = '';

    public string $day_of_week = '1';

    public string $start_time = '09:00';

    public string $end_time = '12:00';

    public int $slot_duration_minutes = 15;

    public bool $is_active = true;

    public function mount(User $provider): void
    {
        $this->authorize('viewAny', ProviderSchedule::class);
        $this->provider = $provider;
    }

    /**
     * @return Collection<int, ProviderSchedule>
     */
    #[Computed]
    public function schedules(): Collection
    {
        return $this->provider->providerSchedules()
            ->with('service')
            ->orderByDesc('is_active')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Active, appointable services in the provider's departments.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function eligibleServices(): Collection
    {
        return Service::query()
            ->active()
            ->where('allows_appointments', true)
            ->whereIn('department_id', $this->provider->departments()->select('departments.id'))
            ->orderBy('name')
            ->get();
    }

    public function edit(int $scheduleId): void
    {
        $schedule = $this->provider->providerSchedules()->findOrFail($scheduleId);
        $this->authorize('update', $schedule);

        $this->editingId = $schedule->id;
        $this->service_id = (string) $schedule->service_id;
        $this->day_of_week = (string) $schedule->day_of_week->value;
        $this->start_time = substr($schedule->start_time, 0, 5);
        $this->end_time = substr($schedule->end_time, 0, 5);
        $this->slot_duration_minutes = $schedule->slot_duration_minutes;
        $this->is_active = $schedule->is_active;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'service_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration_minutes', 'is_active');
        $this->resetErrorBag();
    }

    public function save(SaveProviderSchedule $save): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $schedule = $this->editingId === null ? null : $this->provider->providerSchedules()->findOrFail($this->editingId);

        $save->handle($actor, $this->provider, [
            'service_id' => $this->service_id === '' ? null : (int) $this->service_id,
            'day_of_week' => (int) $this->day_of_week,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'slot_duration_minutes' => $this->slot_duration_minutes,
            'is_active' => $this->is_active,
        ], $schedule);

        $this->cancelEdit();
        unset($this->schedules);
    }

    public function toggleActive(int $scheduleId, SaveProviderSchedule $save): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $schedule = $this->provider->providerSchedules()->findOrFail($scheduleId);

        $save->handle($actor, $this->provider, [
            'service_id' => $schedule->service_id,
            'day_of_week' => $schedule->day_of_week->value,
            'start_time' => substr($schedule->start_time, 0, 5),
            'end_time' => substr($schedule->end_time, 0, 5),
            'slot_duration_minutes' => $schedule->slot_duration_minutes,
            'is_active' => ! $schedule->is_active,
        ], $schedule);

        unset($this->schedules);
    }

    public function render(): View
    {
        return view('livewire.staff.provider-schedules', ['days' => DayOfWeek::cases()]);
    }
}
