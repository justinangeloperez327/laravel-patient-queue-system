<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for date-range reporting queries (phase 07). Read-only reporting
 * otherwise uses the existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS queue_tickets_queue_date_index ON queue_tickets (queue_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS queue_tickets_served_by_queue_date_index ON queue_tickets (served_by, queue_date) WHERE served_by IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS visits_checked_in_at_index ON visits (checked_in_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS appointments_scheduled_start_at_index ON appointments (scheduled_start_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointments_scheduled_start_at_index');
        DB::statement('DROP INDEX IF EXISTS visits_checked_in_at_index');
        DB::statement('DROP INDEX IF EXISTS queue_tickets_served_by_queue_date_index');
        DB::statement('DROP INDEX IF EXISTS queue_tickets_queue_date_index');
    }
};
