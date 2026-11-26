<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ProviderScheduleException;
use App\Models\Service;
use App\Services\AvailableSlot;
use App\Services\ProviderAvailability;
use Carbon\CarbonImmutable;

function slotStarts(array $fixture, string $date = '2030-01-07'): array
{
    return array_map(
        fn (AvailableSlot $slot) => $slot->start->format('H:i'),
        app(ProviderAvailability::class)->slots($fixture['doctor'], $fixture['service'], CarbonImmutable::parse($date)),
    );
}

test('slots follow the weekly schedule', function () {
    $fixture = bookingFixture();

    expect(slotStarts($fixture))->toHaveCount(12)
        ->and(slotStarts($fixture)[0])->toBe('09:00')
        ->and(slotStarts($fixture)[11])->toBe('11:45')
        ->and(slotStarts($fixture, '2030-01-08'))->toBe([]); // no Tuesday block
});

test('past slots are not offered', function () {
    $fixture = bookingFixture();
    $this->travelTo(CarbonImmutable::parse('2030-01-07 10:20:00', 'UTC'));

    expect(slotStarts($fixture)[0])->toBe('10:30');
});

test('booked slots are removed and cancelled ones return', function () {
    $fixture = bookingFixture();
    $appointment = book($fixture, '2030-01-07 09:15:00');

    expect(slotStarts($fixture))->not->toContain('09:15');

    $appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'x'])->save();

    expect(slotStarts($fixture))->toContain('09:15');
});

test('bookings for another service also block the provider', function () {
    $fixture = bookingFixture();
    $other = Service::factory()->appointable()->create(['department_id' => $fixture['service']->department_id]);
    Appointment::factory()->create([
        'provider_id' => $fixture['doctor']->id, 'service_id' => $other->id,
        'scheduled_start_at' => '2030-01-07 09:00:00', 'scheduled_end_at' => '2030-01-07 09:40:00',
    ]);

    expect(slotStarts($fixture))->not->toContain('09:00')->not->toContain('09:15')->not->toContain('09:30')
        ->and(slotStarts($fixture))->toContain('09:45');
});

test('unavailability periods remove overlapping slots', function () {
    $fixture = bookingFixture();
    ProviderScheduleException::factory()->create([
        'provider_id' => $fixture['doctor']->id, 'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 11:00:00',
    ]);

    expect(slotStarts($fixture))->toBe(['09:00', '09:15', '09:30', '09:45', '11:00', '11:15', '11:30', '11:45']);
});

test('schedule times are clinic-local', function () {
    config(['clinic.timezone' => 'Asia/Manila']);
    $fixture = bookingFixture();

    // The Monday block applies to Monday in Manila, not to the UTC weekday.
    expect(app(ProviderAvailability::class)->slots($fixture['doctor'], $fixture['service'], CarbonImmutable::parse('2030-01-08', 'Asia/Manila')))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2030-01-06 20:00:00', 'UTC')); // 04:00 Monday in Manila
    $monday = app(ProviderAvailability::class)->slots($fixture['doctor'], $fixture['service'], CarbonImmutable::parse('2030-01-07', 'Asia/Manila'));

    expect($monday[0]->start->utc()->format('Y-m-d H:i'))->toBe('2030-01-07 01:00')
        ->and($monday[0]->localLabel())->toBe('09:00–09:15');
});
