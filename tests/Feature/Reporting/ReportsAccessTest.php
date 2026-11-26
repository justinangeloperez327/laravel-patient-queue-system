<?php

use App\Enums\Role;
use App\Livewire\Reports\ReportsPage;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\Reporting\ReportTables;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

function exportUrl(array $query): string
{
    return route('reports.export', ['from' => '2030-01-07', 'to' => '2030-01-08', ...$query]);
}

/**
 * @return list<list<string>>
 */
function parseCsv(string $body): array
{
    $body = preg_replace('/^\x{FEFF}/u', '', $body);

    return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", trim($body)))));
}

test('reports are for administrators only', function (Role $role) {
    $user = staffWithRole($role);

    $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
    $this->actingAs($user)->get(exportUrl(['format' => 'csv']))->assertForbidden();
    expect(AuditLog::query()->where('action', 'report.exported')->count())->toBe(0);
})->with([Role::Receptionist, Role::Nurse, Role::Doctor, Role::ServiceStaff]);

test('the reports page shows the fixture figures', function () {
    reportFixture();

    Livewire::actingAs(admin())->test(ReportsPage::class)
        ->set('from', '2030-01-07')
        ->set('to', '2030-01-08')
        ->assertSee('Distinct patients')
        ->assertSeeInOrder(['Visits', '4'])
        ->assertSee('20.0 min')
        ->assertSee('50.0 %')
        ->assertSee('2 of 4 eligible bookings')
        ->assertSee('Provider A');
});

test('invalid filters are reported instead of failing', function () {
    Livewire::actingAs(admin())->test(ReportsPage::class)
        ->set('from', '2030-01-08')
        ->set('to', '2030-01-01')
        ->assertHasErrors(['to'])
        ->assertDontSee('By service');
});

test('a CSV export is aggregate-only and audited with its filters', function () {
    $ids = reportFixture();
    $admin = admin();

    $response = $this->actingAs($admin)->get(exportUrl(['format' => 'csv', 'table' => 'service', 'provider_id' => $ids['a']]))->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('operational-report-2030-01-07-to-2030-01-08-service.csv');

    $body = $response->streamedContent();
    $rows = parseCsv($body);

    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue()
        ->and($rows[0])->toBe(['Prefix', 'Service', 'Tickets issued', 'Tickets served', 'Wait mean (min)', 'Wait median (min)', 'Service mean (min)', 'Eligible bookings', 'No-shows', 'No-show rate (%)'])
        ->and($rows[1])->toBe(['L', 'Lab', '2', '2', '15', '15', '22.5', '3', '1', '33.3']);

    $patientNames = Patient::query()->pluck('last_name')->all();
    foreach ($patientNames as $name) {
        expect($body)->not->toContain($name);
    }

    $log = AuditLog::query()->where('action', 'report.exported')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->metadata)->toEqual(['format' => 'csv', 'table' => 'service', 'filters' => ['from' => '2030-01-07', 'to' => '2030-01-08', 'service_id' => null, 'provider_id' => $ids['a']]]);
});

test('the Excel export contains one sheet per table', function () {
    reportFixture();

    $response = $this->actingAs(admin())->get(exportUrl(['format' => 'xlsx']))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }
    $reader->close();
    unlink($path);

    expect(array_keys($sheets))->toBe(['Summary', 'By service', 'By provider', 'By day'])
        ->and($sheets['Summary'][3])->toBe(['Visits', 4])
        ->and($sheets['Summary'][4])->toBe(['Distinct patients', 3])
        ->and($sheets['Summary'][20])->toEqual(['No-show rate (%)', 50])
        ->and($sheets['By day'][1])->toEqual(['2030-01-07', 2, 2, 2, 20, 10])
        ->and(AuditLog::query()->where('action', 'report.exported')->sole()->metadata['table'])->toBe('all');
});

test('export options are validated', function () {
    $this->actingAs(admin())->get(exportUrl(['format' => 'pdf']))->assertSessionHasErrors('format');
    $this->actingAs(admin())->get(exportUrl(['format' => 'csv', 'table' => 'patients']))->assertSessionHasErrors('table');
    $this->actingAs(admin())->get(route('reports.export', ['format' => 'csv', 'from' => '2030-01-08', 'to' => '2030-01-01']))->assertSessionHasErrors('to');

    expect(AuditLog::query()->where('action', 'report.exported')->count())->toBe(0);
});

test('spreadsheet formula injection is neutralised', function () {
    expect(ReportTables::safeCell('=HYPERLINK("http://x")'))->toBe('\'=HYPERLINK("http://x")')
        ->and(ReportTables::safeCell('+1'))->toBe("'+1")
        ->and(ReportTables::safeCell('-2'))->toBe("'-2")
        ->and(ReportTables::safeCell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(ReportTables::safeCell('Dr Smith'))->toBe('Dr Smith')
        ->and(ReportTables::safeCell(-2))->toBe(-2)
        ->and(ReportTables::safeCell(null))->toBeNull();

    $ids = reportFixture();
    User::query()->whereKey($ids['a'])->update(['name' => '=cmd|calc']);

    $rows = parseCsv($this->actingAs(admin())->get(exportUrl(['format' => 'csv', 'table' => 'provider']))->streamedContent());
    expect($rows[1][0])->toBe("'=cmd|calc");
});

test('the dashboard shows today\'s KPIs to administrators and scoped queue counts to staff', function () {
    $fixture = queueFixture();
    walkInTicket($fixture); // L
    walkInTicket($fixture, $fixture['services']['t']); // T

    $this->actingAs(admin())->get(route('dashboard'))->assertOk()->assertSee('Today')->assertSee('Mean wait')->assertSee('Queues now');
    $this->actingAs($fixture['receptionist'])->get(route('dashboard'))->assertOk()->assertDontSee('Mean wait')
        ->assertSee('Queues now')->assertSee('L — Laboratory')->assertSee('T — Triage');
    $this->actingAs($fixture['nurse'])->get(route('dashboard'))->assertOk()->assertDontSee('Mean wait')
        ->assertSee('T — Triage')->assertDontSee('L — Laboratory');

    $outsider = staffWithRole(Role::Doctor); // no department
    $this->actingAs($outsider)->get(route('dashboard'))->assertOk()->assertDontSee('Queues now');
});

test('the sidebar links reports for administrators only', function () {
    $this->actingAs(admin())->get(route('dashboard'))->assertSee(route('reports.index'), false);
    $this->actingAs(receptionist())->get(route('dashboard'))->assertDontSee(route('reports.index'), false);
});
