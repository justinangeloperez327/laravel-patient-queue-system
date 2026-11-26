<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * Flattens the operational report into export tables (header row + rows).
 * Aggregates only — no patient identifiers.
 */
final class ReportTables
{
    public const array TABLES = ['summary', 'service', 'provider', 'day'];

    public function __construct(private readonly OperationalReport $report) {}

    /**
     * @return list<list<string|int|float|null>>
     */
    public function table(string $table, ReportFilters $filters): array
    {
        return match ($table) {
            'summary' => $this->summary($filters),
            'service' => $this->service($filters),
            'provider' => $this->provider($filters),
            'day' => $this->day($filters),
            default => throw new \InvalidArgumentException("Unknown report table [{$table}]."),
        };
    }

    public static function title(string $table): string
    {
        return match ($table) {
            'summary' => 'Summary',
            'service' => 'By service',
            'provider' => 'By provider',
            'day' => 'By day',
            default => $table,
        };
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function summary(ReportFilters $filters): array
    {
        $s = $this->report->summary($filters);

        return [
            ['Metric', 'Value'],
            ['From', $filters->from->toDateString()],
            ['To', $filters->to->toDateString()],
            ['Visits', $s['visits']],
            ['Distinct patients', $s['distinct_patients']],
            ['Visits completed', $s['visits_completed']],
            ['Visits left without service', $s['visits_left_without_service']],
            ['Visits cancelled', $s['visits_cancelled']],
            ['Tickets issued', $s['tickets_issued']],
            ['Tickets served', $s['tickets_served']],
            ['Tickets cancelled', $s['tickets_cancelled']],
            ['Wait mean (min)', $s['wait']['mean']],
            ['Wait median (min)', $s['wait']['median']],
            ['Wait 90th percentile (min)', $s['wait']['p90']],
            ['Service mean (min)', $s['service']['mean']],
            ['Service median (min)', $s['service']['median']],
            ['Service 90th percentile (min)', $s['service']['p90']],
            ['Appointments booked', $s['appointments']['booked']],
            ['Eligible scheduled bookings', $s['appointments']['eligible']],
            ['No-shows', $s['appointments']['no_shows']],
            ['No-show rate (%)', $s['appointments']['no_show_rate']],
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function service(ReportFilters $filters): array
    {
        $rows = [['Prefix', 'Service', 'Tickets issued', 'Tickets served', 'Wait mean (min)', 'Wait median (min)', 'Service mean (min)', 'Eligible bookings', 'No-shows', 'No-show rate (%)']];

        foreach ($this->report->byService($filters) as $row) {
            $rows[] = [$row['prefix'], $row['name'], $row['tickets_issued'], $row['tickets_served'], $row['wait_mean'], $row['wait_median'], $row['service_mean'], $row['appointments_eligible'], $row['no_shows'], $row['no_show_rate']];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function provider(ReportFilters $filters): array
    {
        $rows = [['Provider', 'Tickets served', 'Wait mean (min)', 'Service mean (min)', 'Eligible bookings', 'No-shows', 'No-show rate (%)']];

        foreach ($this->report->byProvider($filters) as $row) {
            $rows[] = [$row['name'], $row['tickets_served'], $row['wait_mean'], $row['service_mean'], $row['appointments_eligible'], $row['no_shows'], $row['no_show_rate']];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function day(ReportFilters $filters): array
    {
        $rows = [['Date', 'Visits', 'Distinct patients', 'Tickets served', 'Wait mean (min)', 'Service mean (min)']];

        foreach ($this->report->byDay($filters) as $row) {
            $rows[] = [$row['date'], $row['visits'], $row['distinct_patients'], $row['tickets_served'], $row['wait_mean'], $row['service_mean']];
        }

        return $rows;
    }

    /**
     * Neutralise spreadsheet formula injection: text starting with = + - @
     * or a control character is prefixed with an apostrophe.
     */
    public static function safeCell(string|int|float|null $value): string|int|float|null
    {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
