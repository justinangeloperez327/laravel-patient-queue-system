<?php

use App\Services\Reporting\ReportFilters;

test('visits and distinct patients are counted separately', function () {
    reportFixture();
    $summary = operationalReport()->summary(reportFilters());

    expect($summary['visits'])->toBe(4)
        ->and($summary['distinct_patients'])->toBe(3)
        ->and($summary['visits_completed'])->toBe(2)
        ->and($summary['visits_left_without_service'])->toBe(1)
        ->and($summary['visits_cancelled'])->toBe(1);
});

test('wait runs from eligibility to start and service from start to end', function () {
    reportFixture();
    $summary = operationalReport()->summary(reportFilters());

    // waits [10, 30, 20]; p90 = percentile_cont: 20 + 0.8 × 10
    expect($summary['wait'])->toBe(['count' => 3, 'mean' => 20.0, 'median' => 20.0, 'p90' => 28.0])
        // services [15, 5, 30] incl. the transferred ticket; mean 50/3; p90 = 15 + 0.8 × 15
        ->and($summary['service'])->toBe(['count' => 3, 'mean' => 16.7, 'median' => 15.0, 'p90' => 27.0])
        ->and([$summary['tickets_issued'], $summary['tickets_served'], $summary['tickets_cancelled']])->toBe([4, 3, 1]);
});

test('the no-show rate uses eligible scheduled bookings as denominator', function () {
    reportFixture();

    // booked 6 in range; eligible = completed + checked in + 2 no-shows = 4; cancelled and pending excluded
    expect(operationalReport()->summary(reportFilters())['appointments'])->toBe([
        'booked' => 6, 'eligible' => 4, 'no_shows' => 2, 'cancelled' => 1, 'no_show_rate' => 50.0,
    ]);
});

test('the service filter narrows tickets, visits and appointments', function () {
    $ids = reportFixture();

    $lab = operationalReport()->summary(reportFilters(['service_id' => $ids['l']]));
    expect($lab['wait'])->toBe(['count' => 2, 'mean' => 15.0, 'median' => 15.0, 'p90' => 19.0])
        ->and($lab['visits'])->toBe(4)
        ->and($lab['appointments']['no_show_rate'])->toBe(33.3); // 1 of 3

    $pharmacy = operationalReport()->summary(reportFilters(['service_id' => $ids['p']]));
    expect([$pharmacy['visits'], $pharmacy['distinct_patients'], $pharmacy['wait']['mean']])->toBe([1, 1, 30.0])
        ->and($pharmacy['appointments'])->toMatchArray(['eligible' => 1, 'no_shows' => 1, 'no_show_rate' => 100.0]);
});

test('the provider filter narrows to what that provider served or was booked for', function () {
    $ids = reportFixture();
    $summary = operationalReport()->summary(reportFilters(['provider_id' => $ids['a']]));

    expect([$summary['visits'], $summary['distinct_patients']])->toBe([2, 1])
        ->and($summary['wait']['mean'])->toBe(15.0)
        ->and($summary['service']['mean'])->toBe(22.5)
        ->and($summary['appointments']['no_show_rate'])->toBe(33.3);
});

test('breakdowns by service, provider and day match the fixture', function () {
    $ids = reportFixture();
    $report = operationalReport();

    expect($report->byService(reportFilters()))->toBe([
        ['service_id' => $ids['l'], 'prefix' => 'L', 'name' => 'Lab', 'tickets_issued' => 3, 'tickets_served' => 2, 'wait_mean' => 15.0, 'wait_median' => 15.0, 'service_mean' => 22.5, 'appointments_eligible' => 3, 'no_shows' => 1, 'no_show_rate' => 33.3],
        ['service_id' => $ids['p'], 'prefix' => 'P', 'name' => 'Pharmacy', 'tickets_issued' => 1, 'tickets_served' => 1, 'wait_mean' => 30.0, 'wait_median' => 30.0, 'service_mean' => 5.0, 'appointments_eligible' => 1, 'no_shows' => 1, 'no_show_rate' => 100.0],
    ]);

    expect($report->byProvider(reportFilters()))->toBe([
        ['provider_id' => $ids['a'], 'name' => 'Provider A', 'tickets_served' => 2, 'wait_mean' => 15.0, 'service_mean' => 22.5, 'appointments_eligible' => 3, 'no_shows' => 1, 'no_show_rate' => 33.3],
        ['provider_id' => $ids['b'], 'name' => 'Provider B', 'tickets_served' => 1, 'wait_mean' => 30.0, 'service_mean' => 5.0, 'appointments_eligible' => 1, 'no_shows' => 1, 'no_show_rate' => 100.0],
    ]);

    expect($report->byDay(reportFilters()))->toBe([
        ['date' => '2030-01-07', 'visits' => 2, 'distinct_patients' => 2, 'tickets_served' => 2, 'wait_mean' => 20.0, 'service_mean' => 10.0],
        ['date' => '2030-01-08', 'visits' => 2, 'distinct_patients' => 2, 'tickets_served' => 1, 'wait_mean' => 20.0, 'service_mean' => 30.0],
    ]);
});

test('empty ranges report zeros and nulls, and every day is listed', function () {
    reportFixture();
    $empty = ReportFilters::fromInput(['from' => '2030-02-01', 'to' => '2030-02-03']);

    expect(operationalReport()->summary($empty))->toMatchArray([
        'visits' => 0, 'distinct_patients' => 0,
        'wait' => ['count' => 0, 'mean' => null, 'median' => null, 'p90' => null],
        'appointments' => ['booked' => 0, 'eligible' => 0, 'no_shows' => 0, 'cancelled' => 0, 'no_show_rate' => null],
    ])->and(array_column(operationalReport()->byDay($empty), 'date'))->toBe(['2030-02-01', '2030-02-02', '2030-02-03']);
});

test('day boundaries follow the clinic timezone', function () {
    config(['clinic.timezone' => 'Asia/Manila']);
    reportFixture();

    // V4 (23:30 UTC on the 8th) is 07:30 on the 9th in Manila; V3/V1/V2 keep their dates.
    expect(operationalReport()->summary(reportFilters())['visits'])->toBe(3)
        ->and(operationalReport()->summary(ReportFilters::fromInput(['from' => '2030-01-09', 'to' => '2030-01-09']))['visits'])->toBe(2);
});

test('filters are validated', function (array $input, string $field) {
    expectValidationError(fn () => ReportFilters::fromInput($input), $field);
})->with([
    'missing from' => [['to' => '2030-01-01'], 'from'],
    'to before from' => [['from' => '2030-01-02', 'to' => '2030-01-01'], 'to'],
    'range too long' => [['from' => '2030-01-01', 'to' => '2031-01-02'], 'to'],
    'unknown service' => [['from' => '2030-01-01', 'to' => '2030-01-01', 'service_id' => 999999], 'service_id'],
    'bad date' => [['from' => '01/01/2030', 'to' => '2030-01-01'], 'from'],
]);
