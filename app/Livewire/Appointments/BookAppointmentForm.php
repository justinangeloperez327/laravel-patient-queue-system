<?php

declare(strict_types=1);

namespace App\Livewire\Appointments;

use App\Actions\Appointments\BookAppointment;
use App\Livewire\Concerns\PicksSlots;
use App\Livewire\Concerns\SearchesPatients;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicTime;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Book appointment')]
final class BookAppointmentForm extends Component
{
    use PicksSlots;
    use SearchesPatients;

    #[Url(as: 'patient')]
    public ?int $patientParam = null;

    public string $reason_for_visit = '';

    public function mount(): void
    {
        $this->authorize('create', Appointment::class);
        $this->slotDate = ClinicTime::today()->toDateString();

        if ($this->patientParam !== null && Patient::query()->whereKey($this->patientParam)->where('is_active', true)->exists()) {
            $this->patient_id = $this->patientParam;
        }
    }

    public function save(BookAppointment $book): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $appointment = $book->handle($actor, [
            'patient_id' => $this->patient_id,
            'service_id' => $this->service_id === '' ? null : (int) $this->service_id,
            'provider_id' => $this->provider_id === '' ? null : (int) $this->provider_id,
            'scheduled_start_at' => $this->scheduled_start_at,
            'reason_for_visit' => $this->reason_for_visit,
        ]);

        session()->flash('status', __('Booked :number.', ['number' => $appointment->appointment_number]));
        $this->redirectRoute('appointments.show', $appointment, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.appointments.book');
    }
}
