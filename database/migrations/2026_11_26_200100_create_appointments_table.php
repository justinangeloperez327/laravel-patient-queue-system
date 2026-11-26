<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_number', 24)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('scheduled_start_at');
            $table->timestampTz('scheduled_end_at');
            $table->text('reason_for_visit')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->foreignId('booked_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestampsTz();

            $table->index(['provider_id', 'scheduled_start_at']);
            $table->index(['patient_id', 'scheduled_start_at']);
            $table->index(['status', 'scheduled_start_at']);
            // Target for the visits composite foreign key (visit patient = appointment patient).
            $table->unique(['id', 'patient_id']);
        });

        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_number_format CHECK (appointment_number ~ '^APT-[0-9]{8}-[0-9]{4,}$')");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_end_after_start CHECK (scheduled_end_at > scheduled_start_at)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_checked_in_consistent CHECK ((checked_in_at IS NOT NULL) = (status IN ('checked_in', 'completed')) OR (status = 'cancelled' AND checked_in_at IS NOT NULL))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_cancellation_consistent CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL) AND (status <> 'cancelled' OR cancellation_reason IS NOT NULL))");

        // Active bookings never overlap for a provider or for a patient (half-open ranges).
        $occupying = "status IN ('scheduled', 'confirmed', 'checked_in', 'completed')";
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_provider_no_overlap EXCLUDE USING gist (provider_id WITH =, tstzrange(scheduled_start_at, scheduled_end_at, '[)') WITH &&) WHERE ({$occupying})");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_patient_no_overlap EXCLUDE USING gist (patient_id WITH =, tstzrange(scheduled_start_at, scheduled_end_at, '[)') WITH &&) WHERE ({$occupying})");
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
