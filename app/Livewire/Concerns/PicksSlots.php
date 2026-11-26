<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailableSlot;
use App\Services\ProviderAvailability;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * Service → provider → date → slot selection.
 */
trait PicksSlots
{
    public string $service_id = '';

    public string $provider_id = '';

    public string $slotDate = '';

    public string $scheduled_start_at = '';

    protected function ignoredAppointment(): ?Appointment
    {
        return null;
    }

    public function updatedServiceId(): void
    {
        $this->provider_id = '';
        $this->scheduled_start_at = '';
    }

    public function updatedProviderId(): void
    {
        $this->scheduled_start_at = '';
    }

    public function updatedSlotDate(): void
    {
        $this->scheduled_start_at = '';
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function serviceOptions(): Collection
    {
        return Service::query()->active()->where('allows_appointments', true)->orderBy('name')->get();
    }

    /**
     * Active providers assigned to the service's department with a schedule for it.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function providerOptions(): Collection
    {
        $service = $this->selectedService();

        if ($service === null) {
            return new Collection;
        }

        return User::query()
            ->providers()
            ->whereHas('departments', fn ($query) => $query->whereKey($service->department_id))
            ->whereHas('providerSchedules', fn ($query) => $query->where('service_id', $service->id)->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<AvailableSlot>
     */
    #[Computed]
    public function availableSlots(): array
    {
        $service = $this->selectedService();
        $provider = $this->provider_id === '' ? null : User::query()->find((int) $this->provider_id);

        if ($service === null || $provider === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->slotDate) !== 1) {
            return [];
        }

        return app(ProviderAvailability::class)->slots(
            $provider,
            $service,
            CarbonImmutable::parse($this->slotDate, ClinicTime::timezone()),
            $this->ignoredAppointment(),
        );
    }

    public function selectedService(): ?Service
    {
        return $this->service_id === '' ? null : Service::query()->find((int) $this->service_id);
    }
}
