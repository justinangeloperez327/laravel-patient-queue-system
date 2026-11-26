<?php

namespace Database\Factories;

use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_number' => fn () => sprintf('V-%s-%04d', now()->format('Ymd'), fake()->unique()->numberBetween(1, 999999)),
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'requested_service_id' => Service::factory(),
            'visit_type' => VisitType::WalkIn,
            'status' => VisitStatus::CheckedIn,
            'reason_for_visit' => null,
            'registered_by' => User::factory(),
            'checked_in_at' => now(),
        ];
    }
}
