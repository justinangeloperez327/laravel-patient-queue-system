<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Support\ClinicTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational KPIs (docs/IMPLEMENTATION_PLAN.md definitions):
 *
 * - visits: check-ins whose clinic-local date is in the range;
 * - distinct patients: unique patients among those visits;
 * - wait: eligible_at → started_at of tickets started (queue date in range);
 * - service duration: started_at → completed_at of completed or
 *   transferred tickets;
 * - no-show rate: no-shows ÷ eligible scheduled bookings, where eligible
 *   bookings are those that reached an outcome (checked in, completed or
 *   no-show) — cancelled and still-pending bookings are excluded.
 *
 * Filters: service → tickets of that service, visits that requested it or
 * had a ticket for it, appointments for it; provider → tickets they served,
 * visits with such a ticket, appointments booked with them.
 *
 * Aggregates only: no patient identifiers leave this class.
 */
final class OperationalReport
{
    /**
     * @return array{
     *     visits: int, distinct_patients: int, visits_completed: int, visits_left_without_service: int, visits_cancelled: int,
     *     tickets_issued: int, tickets_served: int, tickets_cancelled: int,
     *     wait: array{count: int, mean: float|null, median: float|null, p90: float|null},
     *     service: array{count: int, mean: float|null, median: float|null, p90: float|null},
     *     appointments: array{booked: int, eligible: int, no_shows: int, cancelled: int, no_show_rate: float|null}
     * }
     */
    public function summary(ReportFilters $filters): array
    {
        $visits = $this->visits($filters)
            ->selectRaw('count(*) as visits')
            ->selectRaw('count(distinct v.patient_id) as patients')
            ->selectRaw("count(*) filter (where v.status = 'completed') as completed")
            ->selectRaw("count(*) filter (where v.status = 'left_without_service') as left_without_service")
            ->selectRaw("count(*) filter (where v.status = 'cancelled') as cancelled")
            ->first();

        $tickets = $this->tickets($filters)
            ->selectRaw('count(*) as issued')
            ->selectRaw('count(*) filter (where t.started_at is not null) as served')
            ->selectRaw("count(*) filter (where t.status = 'cancelled') as cancelled")
            ->first();

        return [
            'visits' => (int) ($visits->visits ?? 0),
            'distinct_patients' => (int) ($visits->patients ?? 0),
            'visits_completed' => (int) ($visits->completed ?? 0),
            'visits_left_without_service' => (int) ($visits->left_without_service ?? 0),
            'visits_cancelled' => (int) ($visits->cancelled ?? 0),
            'tickets_issued' => (int) ($tickets->issued ?? 0),
            'tickets_served' => (int) ($tickets->served ?? 0),
            'tickets_cancelled' => (int) ($tickets->cancelled ?? 0),
            'wait' => $this->stats($this->tickets($filters)->whereNotNull('t.started_at'), $this->waitMinutes()),
            'service' => $this->stats($this->completedTickets($filters), $this->serviceMinutes()),
            'appointments' => $this->appointmentStats($this->appointments($filters)),
        ];
    }

    /**
     * @return list<array{service_id: int, prefix: string, name: string, tickets_issued: int, tickets_served: int, wait_mean: float|null, wait_median: float|null, service_mean: float|null, appointments_eligible: int, no_shows: int, no_show_rate: float|null}>
     */
    public function byService(ReportFilters $filters): array
    {
        $tickets = $this->tickets($filters)
            ->groupBy('t.service_id')
            ->select('t.service_id')
            ->selectRaw('count(*) as issued')
            ->selectRaw('count(*) filter (where t.started_at is not null) as served')
            ->selectRaw('avg('.$this->waitMinutes().') filter (where t.started_at is not null) as wait_mean')
            ->selectRaw('percentile_cont(0.5) within group (order by '.$this->waitMinutes().') filter (where t.started_at is not null) as wait_median')
            ->selectRaw("avg({$this->serviceMinutes()}) filter (where t.status in ('completed', 'transferred')) as service_mean")
            ->get()
            ->keyBy('service_id');

        $appointments = $this->appointments($filters)
            ->groupBy('a.service_id')
            ->select('a.service_id')
            ->selectRaw("count(*) filter (where a.status in ('checked_in', 'completed', 'no_show')) as eligible")
            ->selectRaw("count(*) filter (where a.status = 'no_show') as no_shows")
            ->get()
            ->keyBy('service_id');

        $ids = $tickets->keys()->merge($appointments->keys())->unique()->all();

        return array_values(DB::table('services')->whereIn('id', $ids === [] ? [0] : $ids)->orderBy('queue_prefix')->get(['id', 'queue_prefix', 'name'])
            ->map(function (object $service) use ($tickets, $appointments): array {
                $t = $tickets->get($service->id);
                $a = $appointments->get($service->id);
                $eligible = (int) ($a->eligible ?? 0);
                $noShows = (int) ($a->no_shows ?? 0);

                return [
                    'service_id' => (int) $service->id,
                    'prefix' => (string) $service->queue_prefix,
                    'name' => (string) $service->name,
                    'tickets_issued' => (int) ($t->issued ?? 0),
                    'tickets_served' => (int) ($t->served ?? 0),
                    'wait_mean' => self::round($t->wait_mean ?? null),
                    'wait_median' => self::round($t->wait_median ?? null),
                    'service_mean' => self::round($t->service_mean ?? null),
                    'appointments_eligible' => $eligible,
                    'no_shows' => $noShows,
                    'no_show_rate' => $eligible === 0 ? null : self::round($noShows / $eligible * 100),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @return list<array{provider_id: int, name: string, tickets_served: int, wait_mean: float|null, service_mean: float|null, appointments_eligible: int, no_shows: int, no_show_rate: float|null}>
     */
    public function byProvider(ReportFilters $filters): array
    {
        $tickets = $this->tickets($filters)
            ->whereNotNull('t.served_by')
            ->whereNotNull('t.started_at')
            ->groupBy('t.served_by')
            ->select('t.served_by as provider_id')
            ->selectRaw('count(*) as served')
            ->selectRaw('avg('.$this->waitMinutes().') as wait_mean')
            ->selectRaw("avg({$this->serviceMinutes()}) filter (where t.status in ('completed', 'transferred')) as service_mean")
            ->get()
            ->keyBy('provider_id');

        $appointments = $this->appointments($filters)
            ->groupBy('a.provider_id')
            ->select('a.provider_id')
            ->selectRaw("count(*) filter (where a.status in ('checked_in', 'completed', 'no_show')) as eligible")
            ->selectRaw("count(*) filter (where a.status = 'no_show') as no_shows")
            ->get()
            ->keyBy('provider_id');

        $ids = $tickets->keys()->merge($appointments->keys())->unique()->all();

        return array_values(DB::table('users')->whereIn('id', $ids === [] ? [0] : $ids)->orderBy('name')->get(['id', 'name'])
            ->map(function (object $user) use ($tickets, $appointments): array {
                $t = $tickets->get($user->id);
                $a = $appointments->get($user->id);
                $eligible = (int) ($a->eligible ?? 0);
                $noShows = (int) ($a->no_shows ?? 0);

                return [
                    'provider_id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'tickets_served' => (int) ($t->served ?? 0),
                    'wait_mean' => self::round($t->wait_mean ?? null),
                    'service_mean' => self::round($t->service_mean ?? null),
                    'appointments_eligible' => $eligible,
                    'no_shows' => $noShows,
                    'no_show_rate' => $eligible === 0 ? null : self::round($noShows / $eligible * 100),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * One row per clinic-local day in the range, including empty days.
     *
     * @return list<array{date: string, visits: int, distinct_patients: int, tickets_served: int, wait_mean: float|null, service_mean: float|null}>
     */
    public function byDay(ReportFilters $filters): array
    {
        $tz = ClinicTime::timezone();

        $visits = $this->visits($filters)
            ->selectRaw('(v.checked_in_at at time zone ?)::date as day', [$tz])
            ->selectRaw('count(*) as visits')
            ->selectRaw('count(distinct v.patient_id) as patients')
            ->groupByRaw('1')
            ->get()
            ->keyBy('day');

        $tickets = $this->tickets($filters)
            ->whereNotNull('t.started_at')
            ->selectRaw("to_char(t.queue_date, 'YYYY-MM-DD') as day")
            ->selectRaw('count(*) as served')
            ->selectRaw('avg('.$this->waitMinutes().') as wait_mean')
            ->selectRaw("avg({$this->serviceMinutes()}) filter (where t.status in ('completed', 'transferred')) as service_mean")
            ->groupByRaw('1')
            ->get()
            ->keyBy('day');

        $rows = [];

        for ($day = $filters->from; $day->lte($filters->to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $v = $visits->get($key);
            $t = $tickets->get($key);
            $rows[] = [
                'date' => $key,
                'visits' => (int) ($v->visits ?? 0),
                'distinct_patients' => (int) ($v->patients ?? 0),
                'tickets_served' => (int) ($t->served ?? 0),
                'wait_mean' => self::round($t->wait_mean ?? null),
                'service_mean' => self::round($t->service_mean ?? null),
            ];
        }

        return $rows;
    }

    private function visits(ReportFilters $filters): Builder
    {
        return DB::table('visits as v')
            ->where('v.checked_in_at', '>=', $filters->startUtc())
            ->where('v.checked_in_at', '<', $filters->endUtc())
            ->when($filters->serviceId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('v.requested_service_id', $filters->serviceId)
                ->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('queue_tickets as vt')->whereColumn('vt.visit_id', 'v.id')->where('vt.service_id', $filters->serviceId))))
            ->when($filters->providerId !== null, fn (Builder $query) => $query
                ->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('queue_tickets as vt')->whereColumn('vt.visit_id', 'v.id')->where('vt.served_by', $filters->providerId)));
    }

    private function tickets(ReportFilters $filters): Builder
    {
        return DB::table('queue_tickets as t')
            ->whereBetween('t.queue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->when($filters->serviceId !== null, fn (Builder $query) => $query->where('t.service_id', $filters->serviceId))
            ->when($filters->providerId !== null, fn (Builder $query) => $query->where('t.served_by', $filters->providerId));
    }

    private function completedTickets(ReportFilters $filters): Builder
    {
        return $this->tickets($filters)->whereIn('t.status', ['completed', 'transferred']);
    }

    private function appointments(ReportFilters $filters): Builder
    {
        return DB::table('appointments as a')
            ->where('a.scheduled_start_at', '>=', $filters->startUtc())
            ->where('a.scheduled_start_at', '<', $filters->endUtc())
            ->when($filters->serviceId !== null, fn (Builder $query) => $query->where('a.service_id', $filters->serviceId))
            ->when($filters->providerId !== null, fn (Builder $query) => $query->where('a.provider_id', $filters->providerId));
    }

    /**
     * @return literal-string
     */
    private function waitMinutes(): string
    {
        return 'greatest(0, extract(epoch from (t.started_at - t.eligible_at)) / 60.0)';
    }

    /**
     * @return literal-string
     */
    private function serviceMinutes(): string
    {
        return 'extract(epoch from (t.completed_at - t.started_at)) / 60.0';
    }

    /**
     * @param  literal-string  $expression
     * @return array{count: int, mean: float|null, median: float|null, p90: float|null}
     */
    private function stats(Builder $query, string $expression): array
    {
        $row = $query
            ->selectRaw('count(*) as n')
            ->selectRaw("avg({$expression}) as mean")
            ->selectRaw("percentile_cont(0.5) within group (order by {$expression}) as median")
            ->selectRaw("percentile_cont(0.9) within group (order by {$expression}) as p90")
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'mean' => self::round($row->mean ?? null),
            'median' => self::round($row->median ?? null),
            'p90' => self::round($row->p90 ?? null),
        ];
    }

    /**
     * @return array{booked: int, eligible: int, no_shows: int, cancelled: int, no_show_rate: float|null}
     */
    private function appointmentStats(Builder $query): array
    {
        $row = $query
            ->selectRaw('count(*) as booked')
            ->selectRaw("count(*) filter (where a.status in ('checked_in', 'completed', 'no_show')) as eligible")
            ->selectRaw("count(*) filter (where a.status = 'no_show') as no_shows")
            ->selectRaw("count(*) filter (where a.status = 'cancelled') as cancelled")
            ->first();

        $eligible = (int) ($row->eligible ?? 0);
        $noShows = (int) ($row->no_shows ?? 0);

        return [
            'booked' => (int) ($row->booked ?? 0),
            'eligible' => $eligible,
            'no_shows' => $noShows,
            'cancelled' => (int) ($row->cancelled ?? 0),
            'no_show_rate' => $eligible === 0 ? null : self::round($noShows / $eligible * 100),
        ];
    }

    private static function round(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 1) : null;
    }
}
