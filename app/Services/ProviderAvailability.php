<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ProviderSchedule;
use App\Models\Service;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;

/**
 * Computes a provider's free slots for a service on a clinic-local date:
 * weekly schedule blocks, minus past times, unavailability periods and the
 * provider's existing bookings (for any service).
 */
final class ProviderAvailability
{
    /**
     * @return list<AvailableSlot>
     */
    public function slots(User $provider, Service $service, CarbonImmutable $localDate, ?Appointment $ignore = null): array
    {
        $tz = ClinicTime::timezone();
        $day = CarbonImmutable::parse($localDate->format('Y-m-d'), $tz);
        $now = CarbonImmutable::now();

        $schedules = ProviderSchedule::query()
            ->where('provider_id', $provider->id)
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->where('day_of_week', $day->isoWeekday())
            ->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        $dayStart = $day->utc();
        $dayEnd = $day->addDay()->utc();

        $busy = Appointment::query()
            ->where('provider_id', $provider->id)
            ->whereIn('status', array_map(static fn (AppointmentStatus $status): string => $status->value, AppointmentStatus::occupying()))
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->id))
            ->where('scheduled_start_at', '<', $dayEnd)
            ->where('scheduled_end_at', '>', $dayStart)
            ->get(['scheduled_start_at', 'scheduled_end_at'])
            ->map(fn (Appointment $appointment): array => [
                CarbonImmutable::instance($appointment->scheduled_start_at),
                CarbonImmutable::instance($appointment->scheduled_end_at),
            ]);

        $blocked = $provider->scheduleExceptions()
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($exception): array => [
                CarbonImmutable::instance($exception->starts_at),
                CarbonImmutable::instance($exception->ends_at),
            ]);

        $unavailable = $busy->concat($blocked);
        $slots = [];

        foreach ($schedules as $schedule) {
            $cursor = CarbonImmutable::parse($day->format('Y-m-d').' '.$schedule->start_time, $tz);
            $blockEnd = CarbonImmutable::parse($day->format('Y-m-d').' '.$schedule->end_time, $tz);

            while ($cursor->addMinutes($schedule->slot_duration_minutes)->lte($blockEnd)) {
                $start = $cursor->utc();
                $end = $cursor->addMinutes($schedule->slot_duration_minutes)->utc();
                $cursor = $cursor->addMinutes($schedule->slot_duration_minutes);

                if ($start->lt($now)) {
                    continue;
                }

                $clashes = $unavailable->contains(fn (array $range): bool => $range[0]->lt($end) && $range[1]->gt($start));

                if (! $clashes) {
                    $slots[$start->toIso8601String()] = new AvailableSlot($start, $end);
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * The free slot starting exactly at $start (UTC), if any.
     */
    public function find(User $provider, Service $service, CarbonImmutable $start, ?Appointment $ignore = null): ?AvailableSlot
    {
        $localDate = $start->setTimezone(ClinicTime::timezone())->startOfDay();

        foreach ($this->slots($provider, $service, $localDate, $ignore) as $slot) {
            if ($slot->start->equalTo($start)) {
                return $slot;
            }
        }

        return null;
    }
}
