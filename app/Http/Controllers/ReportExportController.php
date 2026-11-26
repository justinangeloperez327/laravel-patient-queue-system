<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\ReportTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permission-scoped CSV/Excel export of the aggregate operational report.
 * Every export is audited with its filters.
 */
final class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportTables $tables): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize(Permission::ExportReports->value);

        /** @var array{format: string, table?: string|null} $options */
        $options = Validator::make($request->only('format', 'table'), [
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'table' => ['nullable', Rule::in(ReportTables::TABLES)],
        ])->validate();

        $filters = ReportFilters::fromInput($request->only('from', 'to', 'service_id', 'provider_id'));
        $table = $options['table'] ?? 'day';
        $stem = sprintf('operational-report-%s-to-%s', $filters->from->toDateString(), $filters->to->toDateString());

        AuditLog::record($user, 'report.exported', $user, [
            'format' => $options['format'],
            'table' => $options['format'] === 'xlsx' ? 'all' : $table,
            'filters' => $filters->toArray(),
        ]);

        if ($options['format'] === 'csv') {
            $rows = $tables->table($table, $filters);

            return response()->streamDownload(function () use ($rows): void {
                $out = fopen('php://output', 'wb');

                if ($out === false) {
                    throw new \RuntimeException('Cannot open the output stream.');
                }

                fwrite($out, "\u{FEFF}"); // UTF-8 BOM so Excel detects the encoding

                foreach ($rows as $row) {
                    fputcsv($out, array_map(ReportTables::safeCell(...), $row), escape: '');
                }

                fclose($out);
            }, "{$stem}-{$table}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        $sheets = [];

        foreach (ReportTables::TABLES as $name) {
            $sheets[$name] = $tables->table($name, $filters);
        }

        return response()->streamDownload(function () use ($sheets): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $first = true;

            foreach ($sheets as $name => $rows) {
                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName(ReportTables::title($name));
                $first = false;

                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues(array_map(ReportTables::safeCell(...), $row)));
                }
            }

            $writer->close();
        }, "{$stem}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }
}
