<?php

declare(strict_types=1);

namespace App\Livewire\Appointments;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Day view of bookings, grouped by provider, in clinic time.
 */
#[Title('Appointments')]
final class AppointmentCalendar extends Component
{
    #[Url]
    public string $date = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $service = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Appointment::class);

        if (! $this->isValidDate($this->date)) {
            $this->date = ClinicTime::today()->toDateString();
        }
    }

    public function updatedDate(): void
    {
        if (! $this->isValidDate($this->date)) {
            $this->date = ClinicTime::today()->toDateString();
        }
    }

    public function shiftDay(int $days): void
    {
        $this->date = $this->day()->addDays(max(-7, min(7, $days)))->toDateString();
    }

    public function today(): void
    {
        $this->date = ClinicTime::today()->toDateString();
    }

    public function day(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date, ClinicTime::timezone())->startOfDay();
    }

    /**
     * @return \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, Appointment>>
     */
    #[Computed]
    public function appointmentsByProvider(): \Illuminate\Support\Collection
    {
        return Appointment::query()
            ->with(['patient', 'service', 'provider'])
            ->onLocalDate($this->day())
            ->when($this->provider !== '', fn ($query) => $query->where('provider_id', (int) $this->provider))
            ->when($this->service !== '', fn ($query) => $query->where('service_id', (int) $this->service))
            ->orderBy('scheduled_start_at')
            ->get()
            ->toBase()
            ->groupBy('provider_id');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function providers(): Collection
    {
        return User::query()->providers()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->where('allows_appointments', true)->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.appointments.calendar');
    }

    private function isValidDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
