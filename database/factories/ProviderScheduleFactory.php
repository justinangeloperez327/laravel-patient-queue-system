<?php

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSchedule>
 */
class ProviderScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => User::factory(),
            'service_id' => Service::factory()->appointable(),
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 15,
            'is_active' => true,
        ];
    }
}
