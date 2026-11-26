<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Generates gap-free daily document numbers such as APT-20261126-0001.
 *
 * A transaction-scoped advisory lock serialises generation per prefix and
 * day; the unique constraint on the column is the final safeguard. Must be
 * called inside the transaction that inserts the row.
 */
final class DailyNumber
{
    /**
     * @param  'appointments'|'visits'  $table
     */
    public static function next(string $prefix, CarbonImmutable $localDate, string $table): string
    {
        [$column, $sequence] = match ($table) {
            'appointments' => ['appointment_number', "split_part(appointment_number, '-', 3)::integer"],
            'visits' => ['visit_number', "split_part(visit_number, '-', 3)::integer"],
        };

        if (DB::transactionLevel() === 0) {
            throw new LogicException('DailyNumber::next() must run inside a database transaction.');
        }

        $day = $localDate->format('Ymd');
        $stem = "{$prefix}-{$day}-";

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["daily-number:{$stem}"]);

        $last = DB::table($table)
            ->where($column, 'like', $stem.'%')
            ->max(DB::raw($sequence));

        return sprintf('%s%04d', $stem, (is_numeric($last) ? (int) $last : 0) + 1);
    }
}
