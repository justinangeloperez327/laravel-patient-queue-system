<?php

namespace Database\Factories;

use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Low-level fixture factory; use the queue actions to exercise engine rules.
 *
 * @extends Factory<QueueTicket>
 */
class QueueTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'visit_id' => Visit::factory(),
            'service_id' => Service::factory(),
            'ticket_number' => sprintf('X-%03d', $sequence),
            'sequence_number' => $sequence,
            'queue_date' => now()->toDateString(),
            'issued_by' => User::factory(),
            'queued_at' => now(),
            'eligible_at' => now(),
        ];
    }
}
