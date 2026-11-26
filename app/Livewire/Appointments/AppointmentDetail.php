<?php

declare(strict_types=1);

namespace App\Livewire\Appointments;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\ConfirmAppointment;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Visits\CheckInAppointment;
use App\Livewire\Concerns\PicksSlots;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ClinicTime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One appointment with its lifecycle actions and audit history.
 */
final class AppointmentDetail extends Component
{
    use PicksSlots;

    #[Locked]
    public Appointment $appointment;

    public bool $rescheduling = false;

    public string $cancellation_reason = '';

    public function mount(Appointment $appointment): void
    {
        $this->authorize('view', $appointment);
        $this->appointment = $appointment;
    }

    protected function ignoredAppointment(): Appointment
    {
        return $this->appointment;
    }

    public function startRescheduling(): void
    {
        $this->authorize('update', $this->appointment);
        $this->rescheduling = true;
        $this->service_id = (string) $this->appointment->service_id;
        $this->provider_id = (string) $this->appointment->provider_id;
        $this->slotDate = $this->appointment->localDate()->toDateString();
        $this->scheduled_start_at = '';
    }

    public function updatedServiceId(): void
    {
        // The service of an existing booking is fixed.
        $this->service_id = (string) $this->appointment->service_id;
    }

    public function reschedule(RescheduleAppointment $reschedule): void
    {
        $this->appointment = $reschedule->handle($this->actor(), $this->appointment, [
            'provider_id' => $this->provider_id === '' ? null : (int) $this->provider_id,
            'scheduled_start_at' => $this->scheduled_start_at,
        ]);
        $this->rescheduling = false;
        $this->flash(__('Rescheduled to :time.', ['time' => ClinicTime::toLocalDisplay($this->appointment->scheduled_start_at)]));
    }

    public function confirm(ConfirmAppointment $confirm): void
    {
        $this->appointment = $confirm->handle($this->actor(), $this->appointment);
        $this->flash(__('Appointment confirmed.'));
    }

    public function cancel(CancelAppointment $cancel): void
    {
        $this->appointment = $cancel->handle($this->actor(), $this->appointment, $this->cancellation_reason);
        $this->cancellation_reason = '';
        $this->flash(__('Appointment cancelled; the slot is free again.'));
    }

    public function markNoShow(MarkAppointmentNoShow $markNoShow): void
    {
        $this->appointment = $markNoShow->handle($this->actor(), $this->appointment);
        $this->flash(__('Recorded as a no-show.'));
    }

    public function checkIn(CheckInAppointment $checkIn): void
    {
        $visit = $checkIn->handle($this->actor(), $this->appointment);
        $this->appointment->refresh();
        $this->flash(__('Checked in as visit :number.', ['number' => $visit->visit_number]));
    }

    /**
     * @return Collection<int, AuditLog>
     */
    #[Computed]
    public function history(): Collection
    {
        return AuditLog::query()
            ->with('actor')
            ->where('subject_type', $this->appointment->getMorphClass())
            ->where('subject_id', $this->appointment->id)
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        $this->appointment->loadMissing(['patient', 'service', 'provider', 'bookedBy', 'visit']);

        return view('livewire.appointments.detail')
            ->title(__('Appointment :number', ['number' => $this->appointment->appointment_number]));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function flash(string $message): void
    {
        session()->flash('status', $message);
        unset($this->history);
    }
}
