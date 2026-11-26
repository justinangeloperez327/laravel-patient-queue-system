<?php

declare(strict_types=1);

namespace App\Livewire\Patients;

use App\Actions\Patients\RegisterPatient;
use App\Actions\Patients\UpdatePatientDemographics;
use App\Enums\Sex;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Register a patient or edit demographics. Validation and authorization
 * live in the actions.
 */
final class PatientForm extends Component
{
    #[Locked]
    public ?Patient $patient = null;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $date_of_birth = '';

    public string $sex = '';

    public string $phone = '';

    public string $email = '';

    public string $nationality = '';

    public string $address = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public bool $is_active = true;

    public bool $confirm_duplicate = false;

    public function mount(?Patient $patient = null): void
    {
        if ($patient === null || ! $patient->exists) {
            $this->authorize('create', Patient::class);
            $this->patient = null;

            return;
        }

        $this->authorize('update', $patient);
        $this->patient = $patient;

        /** @var User $viewer */
        $viewer = Auth::user();
        AuditLog::record($viewer, 'patient.viewed', $patient, ['context' => 'edit']);

        $this->first_name = $patient->first_name;
        $this->middle_name = $patient->middle_name ?? '';
        $this->last_name = $patient->last_name;
        $this->date_of_birth = $patient->date_of_birth->toDateString();
        $this->sex = $patient->sex->value ?? '';
        $this->phone = $patient->phone ?? '';
        $this->email = $patient->email ?? '';
        $this->nationality = $patient->nationality ?? '';
        $this->address = $patient->address ?? '';
        $this->emergency_contact_name = $patient->emergency_contact_name ?? '';
        $this->emergency_contact_phone = $patient->emergency_contact_phone ?? '';
        $this->is_active = $patient->is_active;
    }

    public function save(RegisterPatient $register, UpdatePatientDemographics $update): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $input = [
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'date_of_birth' => $this->date_of_birth,
            'sex' => $this->sex,
            'phone' => $this->phone,
            'email' => $this->email,
            'nationality' => $this->nationality,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
        ];

        if ($this->patient === null) {
            $patient = $register->handle($actor, [...$input, 'confirm_duplicate' => $this->confirm_duplicate]);
            session()->flash('status', __('Registered patient :number.', ['number' => $patient->patient_number]));
        } else {
            $patient = $update->handle($actor, $this->patient, [...$input, 'is_active' => $this->is_active]);
            session()->flash('status', __('Updated patient :number.', ['number' => $patient->patient_number]));
        }

        $this->redirectRoute('patients.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.patients.form', [
            'sexes' => Sex::cases(),
        ])->title($this->patient === null ? __('Register patient') : __('Edit patient'));
    }
}
