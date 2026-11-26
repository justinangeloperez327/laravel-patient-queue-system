<?php

declare(strict_types=1);

namespace App\Livewire\Reception;

use App\Actions\Queues\IssueTicket;
use App\Actions\Visits\CheckInAppointment;
use App\Actions\Visits\RegisterWalkIn;
use App\Enums\AppointmentStatus;
use App\Enums\TriageMode;
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
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Today's front desk: check in booked patients, register walk-ins and see
 * who is currently checked in. Check-in issues the first queue ticket.
 */
#[Title('Reception')]
final class ReceptionDesk extends Component
{
    use ClosesVisits;
    use SearchesPatients;

    public string $service_id = '';

    public string $reason_for_visit = '';

    public bool $sendToTriage = false;

    #[Locked]
    public ?int $issuingVisitId = null;

    public string $issueServiceId = '';

    public bool $issueToTriage = false;

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
            ->with(['patient', 'requestedService', 'tickets'])
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

    public function checkIn(int $appointmentId, CheckInAppointment $checkIn, bool $triage = false): void
    {
        $visit = $checkIn->handle($this->actor(), Appointment::query()->findOrFail($appointmentId), $triage);

        $this->flashTicket($visit);
        $this->refreshLists();
    }

    public function checkInToTriage(int $appointmentId, CheckInAppointment $checkIn): void
    {
        $this->checkIn($appointmentId, $checkIn, true);
    }

    public function startIssuing(int $visitId): void
    {
        $this->issuingVisitId = Visit::query()->findOrFail($visitId)->id;
        $this->issueServiceId = '';
        $this->issueToTriage = false;
        $this->resetErrorBag();
    }

    public function issueTicket(IssueTicket $issue): void
    {
        $visit = Visit::query()->findOrFail($this->issuingVisitId);
        $ticket = $issue->handle($this->actor(), $visit, Service::query()->findOrFail((int) $this->issueServiceId), $this->issueToTriage);

        session()->flash('status', __('Issued ticket :ticket for visit :visit.', ['ticket' => $ticket->ticket_number, 'visit' => $visit->visit_number]));
        $this->issuingVisitId = null;
        $this->refreshLists();
    }

    #[Computed]
    public function selectedServiceHasOptionalTriage(): bool
    {
        return $this->service_id !== '' && Service::query()->whereKey((int) $this->service_id)->where('triage_mode', TriageMode::Optional->value)->exists();
    }

    public function registerWalkIn(RegisterWalkIn $register): void
    {
        $visit = $register->handle($this->actor(), [
            'patient_id' => $this->patient_id,
            'service_id' => $this->service_id === '' ? null : (int) $this->service_id,
            'reason_for_visit' => $this->reason_for_visit,
            'send_to_triage' => $this->sendToTriage,
        ]);

        $this->flashTicket($visit);
        $this->reset('patient_id', 'service_id', 'reason_for_visit', 'patientSearch', 'sendToTriage');
        $this->refreshLists();
    }

    public function render(): View
    {
        return view('livewire.reception.desk');
    }

    private function flashTicket(Visit $visit): void
    {
        $ticket = $visit->tickets()->latest('id')->first();

        session()->flash('status', __('Checked in :name (visit :visit). Ticket :ticket.', [
            'name' => $visit->patient->fullName(),
            'visit' => $visit->visit_number,
            'ticket' => $ticket->ticket_number ?? '—',
        ]));
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
