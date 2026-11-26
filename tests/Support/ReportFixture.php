<?php

use App\Models\Department;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Services\Reporting\OperationalReport;
use App\Services\Reporting\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A hand-built, fully known data set (clinic timezone UTC unless changed).
 * Expected values in the tests are computed by hand from these rows.
 *
 * Visits (range 2030-01-07 … 2030-01-08):
 *   V1 P1 07 09:00 completed  (req L)    V2 P1 08 10:00 completed (req L)
 *   V3 P2 07 11:00 left w/o service (L)  V4 P3 08 23:30 cancelled (req L)
 *   V5 P4 09 08:00 completed — outside the range
 * Tickets (wait = eligible→start, service = start→end):
 *   T1 V1 L 07 wait 10 svc 15 completed by A (queued 20 min before eligible)   T2 V1 P 07 wait 30 svc 5 completed by B
 *   T3 V2 L 08 wait 20 svc 30 transferred by A  T4 V3 L 07 never started, cancelled
 *   T5 V5 L 09 — outside the range
 * Appointments (L/A unless noted): A1 completed, A2 checked in, A3 no-show,
 *   A4 no-show (P/B), A5 cancelled, A6 still scheduled, A7 no-show outside the range.
 *
 * @return array<string, int>
 */
function reportFixture(): array
{
    $at = fn (string $time) => CarbonImmutable::parse($time, 'UTC');
    $lab = Department::factory()->create();
    $l = Service::factory()->create(['department_id' => $lab->id, 'queue_prefix' => 'L', 'name' => 'Lab']);
    $p = Service::factory()->create(['department_id' => $lab->id, 'queue_prefix' => 'P', 'name' => 'Pharmacy']);
    $a = User::factory()->create(['name' => 'Provider A']);
    $b = User::factory()->create(['name' => 'Provider B']);
    $clerk = User::factory()->create();
    [$p1, $p2, $p3, $p4] = Patient::factory()->count(4)->create()->all();

    $visit = function (string $number, $patient, string $checkedIn, string $status) use ($l, $clerk, $at) {
        return DB::table('visits')->insertGetId([
            'visit_number' => $number, 'patient_id' => $patient->id, 'requested_service_id' => $l->id,
            'visit_type' => 'walk_in', 'status' => $status, 'registered_by' => $clerk->id,
            'checked_in_at' => $at($checkedIn),
            'completed_at' => $status === 'completed' ? $at($checkedIn)->addHour() : null,
            'cancelled_at' => in_array($status, ['cancelled', 'left_without_service'], true) ? $at($checkedIn)->addHour() : null,
        ]);
    };
    $v1 = $visit('V-20300107-0001', $p1, '2030-01-07 09:00', 'completed');
    $v2 = $visit('V-20300108-0001', $p1, '2030-01-08 10:00', 'completed');
    $v3 = $visit('V-20300107-0002', $p2, '2030-01-07 11:00', 'left_without_service');
    $v4 = $visit('V-20300108-0002', $p3, '2030-01-08 23:30', 'cancelled');
    $v5 = $visit('V-20300109-0001', $p4, '2030-01-09 08:00', 'completed');

    $ticket = function (int $visitId, $service, int $seq, string $date, string $eligible, ?string $start, ?string $end, string $status, $by, ?string $queued = null) use ($clerk, $at) {
        DB::table('queue_tickets')->insert([
            'visit_id' => $visitId, 'service_id' => $service->id, 'ticket_number' => sprintf('%s-%03d', $service->queue_prefix, $seq),
            'sequence_number' => $seq, 'queue_date' => $date, 'status' => $status, 'issued_by' => $clerk->id,
            'queued_at' => $at($queued ?? $eligible), 'eligible_at' => $at($eligible),
            'called_at' => $start ? $at($start) : null, 'started_at' => $start ? $at($start) : null,
            'completed_at' => $end ? $at($end) : null, 'served_by' => $by?->id,
        ]);
    };
    // Early appointment arrival: queued 08:40, eligible 09:00 — wait counts from eligibility (10 min), not arrival.
    $ticket($v1, $l, 1, '2030-01-07', '2030-01-07 09:00', '2030-01-07 09:10', '2030-01-07 09:25', 'completed', $a, '2030-01-07 08:40');
    $ticket($v1, $p, 1, '2030-01-07', '2030-01-07 09:25', '2030-01-07 09:55', '2030-01-07 10:00', 'completed', $b);
    $ticket($v2, $l, 1, '2030-01-08', '2030-01-08 10:00', '2030-01-08 10:20', '2030-01-08 10:50', 'transferred', $a);
    $ticket($v3, $l, 2, '2030-01-07', '2030-01-07 11:00', null, null, 'cancelled', null);
    $ticket($v5, $l, 1, '2030-01-09', '2030-01-09 08:00', '2030-01-09 09:00', '2030-01-09 09:10', 'completed', $a);

    $appointment = function (int $n, string $start, string $status, $service, $provider) use ($clerk, $at) {
        $patient = Patient::factory()->create();
        DB::table('appointments')->insert([
            'appointment_number' => sprintf('APT-20300101-%04d', $n), 'patient_id' => $patient->id, 'service_id' => $service->id,
            'provider_id' => $provider->id, 'booked_by' => $clerk->id, 'status' => $status,
            'scheduled_start_at' => $at($start), 'scheduled_end_at' => $at($start)->addMinutes(15),
            'checked_in_at' => in_array($status, ['checked_in', 'completed'], true) ? $at($start) : null,
            'cancelled_at' => $status === 'cancelled' ? $at($start) : null,
            'cancellation_reason' => $status === 'cancelled' ? 'Patient request' : null,
        ]);
    };
    $appointment(1, '2030-01-07 09:00', 'completed', $l, $a);
    $appointment(2, '2030-01-07 09:30', 'checked_in', $l, $a);
    $appointment(3, '2030-01-07 10:00', 'no_show', $l, $a);
    $appointment(4, '2030-01-08 10:00', 'no_show', $p, $b);
    $appointment(5, '2030-01-08 11:00', 'cancelled', $l, $a);
    $appointment(6, '2030-01-08 12:00', 'scheduled', $l, $a);
    $appointment(7, '2030-01-09 09:00', 'no_show', $l, $a);

    return ['l' => $l->id, 'p' => $p->id, 'a' => $a->id, 'b' => $b->id];
}

function operationalReport(): OperationalReport
{
    return app(OperationalReport::class);
}

function reportFilters(array $overrides = []): ReportFilters
{
    return ReportFilters::fromInput(['from' => '2030-01-07', 'to' => '2030-01-08', ...$overrides]);
}
