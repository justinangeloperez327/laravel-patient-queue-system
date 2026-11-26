<?php

namespace Database\Factories;

use App\Enums\TriageMode;
use App\Models\Department;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => ucfirst(fake()->unique()->word()).' service '.fake()->unique()->numberBetween(1, 99999),
            'queue_prefix' => fake()->unique()->regexify('[A-Z]{3}'),
            'triage_mode' => TriageMode::None,
            'triage_service_id' => null,
            'allows_appointments' => false,
            'estimated_duration_minutes' => 15,
            'is_active' => true,
        ];
    }

    public function appointable(): static
    {
        return $this->state(fn () => ['allows_appointments' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function triagedBy(Service $triageService, TriageMode $mode = TriageMode::Required): static
    {
        return $this->state(fn () => [
            'triage_mode' => $mode,
            'triage_service_id' => $triageService->id,
        ]);
    }
}
