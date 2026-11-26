<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Schedules\CreateScheduleException;
use App\Actions\Schedules\DeleteScheduleException;
use App\Models\ProviderSchedule;
use App\Models\ProviderScheduleException;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One-off unavailability periods of one provider.
 */
final class ScheduleExceptions extends Component
{
    #[Locked]
    public User $provider;

    public string $starts_at = '';

    public string $ends_at = '';

    public string $reason = '';

    public bool $showPast = false;

    public function mount(User $provider): void
    {
        $this->authorize('viewAny', ProviderSchedule::class);
        $this->provider = $provider;
    }

    /**
     * @return Collection<int, ProviderScheduleException>
     */
    #[Computed]
    public function exceptions(): Collection
    {
        return $this->provider->scheduleExceptions()
            ->when(! $this->showPast, fn ($query) => $query->where('ends_at', '>', now()))
            ->orderBy('starts_at')
            ->get();
    }

    public function save(CreateScheduleException $create): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $create->handle($actor, $this->provider, [
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'reason' => $this->reason,
        ]);

        $this->reset('starts_at', 'ends_at', 'reason');
        unset($this->exceptions);
    }

    public function delete(int $exceptionId, DeleteScheduleException $delete): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $delete->handle($actor, $this->provider->scheduleExceptions()->findOrFail($exceptionId));
        unset($this->exceptions);
    }

    public function render(): View
    {
        return view('livewire.staff.schedule-exceptions');
    }
}
