<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailableSlot;
use App\Services\ProviderAvailability;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Booking rules shared by booking and rescheduling.
 */
trait ValidatesBookings
{
    abstract protected function availability(): ProviderAvailability;

    protected function ensureBookable(Patient $patient, Service $service, User $provider): void
    {
        if (! $patient->is_active) {
            throw ValidationException::withMessages(['patient_id' => __('This patient record is inactive.')]);
        }

        if (! $service->is_active || ! $service->allows_appointments) {
            throw ValidationException::withMessages(['service_id' => __('Choose an active service that accepts appointments.')]);
        }

        if (! $provider->is_active || ! $provider->isProvider() || ! $provider->isAssignedToDepartment($service->department_id)) {
            throw ValidationException::withMessages(['provider_id' => __('This provider cannot take appointments for this service.')]);
        }
    }

    protected function ensureWithinHorizon(CarbonImmutable $start): void
    {
        $horizon = config('clinic.appointments.booking_horizon_days');
        $days = is_int($horizon) ? $horizon : 90;
        $localDate = $start->setTimezone(ClinicTime::timezone())->startOfDay();

        if ($localDate->gt(ClinicTime::today()->addDays($days))) {
            throw ValidationException::withMessages([
                'scheduled_start_at' => __('Appointments can be booked up to :days days ahead.', ['days' => $days]),
            ]);
        }
    }

    protected function requireSlot(User $provider, Service $service, CarbonImmutable $start, ?Appointment $ignore = null): AvailableSlot
    {
        $slot = $this->availability()->find($provider, $service, $start, $ignore);

        if ($slot === null) {
            throw ValidationException::withMessages([
                'scheduled_start_at' => __('This time is not an available slot for the provider. Choose another slot.'),
            ]);
        }

        return $slot;
    }

    protected function ensurePatientFree(Patient $patient, AvailableSlot $slot, ?Appointment $ignore = null): void
    {
        $clash = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', array_map(static fn (AppointmentStatus $status): string => $status->value, AppointmentStatus::occupying()))
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->id))
            ->where('scheduled_start_at', '<', $slot->end)
            ->where('scheduled_end_at', '>', $slot->start)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['patient_id' => __('The patient already has an appointment at this time.')]);
        }
    }

    protected function parseStart(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages(['scheduled_start_at' => __('Choose a slot.')]);
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['scheduled_start_at' => __('Choose a slot.')]);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function bookingConstraints(): array
    {
        return [
            'appointments_provider_no_overlap' => ['scheduled_start_at', __('This slot was just booked by someone else. Choose another slot.')],
            'appointments_patient_no_overlap' => ['patient_id', __('The patient already has an appointment at this time.')],
        ];
    }
}
