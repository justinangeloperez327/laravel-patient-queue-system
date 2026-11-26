<?php

declare(strict_types=1);

namespace App\Livewire\Reception;

use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\AppointmentStatus;
use App\Enums\VisitStatus;
use App\Livewire\Concerns\ClosesVisits;
use App\Livewire\Concerns\SearchesPatients;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\ClinicTime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Today's front desk: check in booked patients, register walk-ins and see
 * who is currently checked in. Ticket issuance is added in phase 04.
 */
#[Title('Reception')]
final class ReceptionDesk extends Component
{
    use ClosesVisits;
    use SearchesPatients;

    public string $service_id = '';

    public string $reason_for_visit = '';

    public function mount(): void
    {
        $this->authorize('create', Visit::class);
    }

    /**
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function todaysAppointments(): Collection
    {
        return Appointment::query()
            ->with(['patient', 'service', 'provider'])
            ->onLocalDate(ClinicTime::today())
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
            ->orderBy('scheduled_start_at')
            ->get();
    }

    /**
     * @return Collection<int, Visit>
     */
    #[Computed]
    public function openVisits(): Collection
    {
        return Visit::query()
            ->with(['patient', 'requestedService'])
            ->whereIn('status', [VisitStatus::CheckedIn->value, VisitStatus::InProgress->value])
            ->orderBy('checked_in_at')
            ->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->active()->orderBy('name')->get();
    }

    public function checkIn(int $appointmentId, CheckInAppointment $checkIn): void
    {
        $visit = $checkIn->handle($this->actor(), Appointment::query()->findOrFail($appointmentId));

        session()->flash('status', __('Checked in :name as visit :number.', ['name' => $visit->patient->fullName(), 'number' => $visit->visit_number]));
        $this->refreshLists();
    }

    public function registerWalkIn(RegisterWalkIn $register): void
    {
        $visit = $register->handle($this->actor(), [
            'patient_id' => $this->patient_id,
            'service_id' => $this->service_id === '' ? null : (int) $this->service_id,
            'reason_for_visit' => $this->reason_for_visit,
        ]);

        session()->flash('status', __('Checked in :name as walk-in visit :number.', ['name' => $visit->patient->fullName(), 'number' => $visit->visit_number]));
        $this->reset('patient_id', 'service_id', 'reason_for_visit', 'patientSearch');
        $this->refreshLists();
    }

    public function render(): View
    {
        return view('livewire.reception.desk');
    }

    private function refreshLists(): void
    {
        unset($this->todaysAppointments, $this->openVisits);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
