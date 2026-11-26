<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Low-level factory for fixtures; it does not check availability. Use the
 * BookAppointment action to exercise booking rules.
 *
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(2)->setTime(9, 0)->addMinutes(15 * fake()->unique()->numberBetween(0, 5000));

        return [
            'appointment_number' => fn () => sprintf('APT-%s-%04d', now()->format('Ymd'), fake()->unique()->numberBetween(1, 999999)),
            'patient_id' => Patient::factory(),
            'service_id' => Service::factory()->appointable(),
            'provider_id' => User::factory(),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addMinutes(15),
            'reason_for_visit' => null,
            'status' => AppointmentStatus::Scheduled,
            'booked_by' => User::factory(),
        ];
    }
}
