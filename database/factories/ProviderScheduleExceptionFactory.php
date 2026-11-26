<?php

namespace Database\Factories;

use App\Models\ProviderScheduleException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderScheduleException>
 */
class ProviderScheduleExceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(7)->startOfHour();

        return [
            'provider_id' => User::factory(),
            'starts_at' => $start,
            'ends_at' => $start->addHours(4),
            'reason' => 'Leave',
        ];
    }
}
