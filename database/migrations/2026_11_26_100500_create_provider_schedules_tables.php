<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring weekly availability. Times are clinic-local wall-clock times.
        Schema::create('provider_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('service_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_duration_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['provider_id', 'day_of_week']);
        });

        DB::statement('ALTER TABLE provider_schedules ADD CONSTRAINT provider_schedules_day_of_week_iso CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE provider_schedules ADD CONSTRAINT provider_schedules_end_after_start CHECK (end_time > start_time)');
        DB::statement('ALTER TABLE provider_schedules ADD CONSTRAINT provider_schedules_slot_duration_range CHECK (slot_duration_minutes BETWEEN 5 AND 240)');
        DB::statement('ALTER TABLE provider_schedules ADD CONSTRAINT provider_schedules_whole_slots CHECK ((EXTRACT(EPOCH FROM (end_time - start_time)) / 60)::integer % slot_duration_minutes = 0)');
        // A provider cannot hold two overlapping active schedules on the same weekday.
        DB::statement(<<<'SQL'
            ALTER TABLE provider_schedules ADD CONSTRAINT provider_schedules_no_overlap
            EXCLUDE USING gist (
                provider_id WITH =,
                day_of_week WITH =,
                tsrange(DATE '2000-01-01' + start_time, DATE '2000-01-01' + end_time, '[)') WITH &&
            ) WHERE (is_active)
            SQL);

        // One-off unavailability (leave, meetings). Absolute instants in UTC.
        Schema::create('provider_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 255);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE provider_schedule_exceptions ADD CONSTRAINT provider_schedule_exceptions_end_after_start CHECK (ends_at > starts_at)');
        DB::statement(<<<'SQL'
            ALTER TABLE provider_schedule_exceptions ADD CONSTRAINT provider_schedule_exceptions_no_overlap
            EXCLUDE USING gist (provider_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_schedule_exceptions');
        Schema::dropIfExists('provider_schedules');
    }
};
