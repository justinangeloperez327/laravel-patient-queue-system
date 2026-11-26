<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('visit_number', 24)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->unique();
            $table->foreignId('requested_service_id')->index()->constrained('services')->restrictOnDelete();
            $table->string('visit_type', 16);
            $table->string('status', 24)->default('checked_in');
            $table->text('reason_for_visit')->nullable();
            $table->foreignId('registered_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_in_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'checked_in_at']);
            $table->index(['patient_id', 'checked_in_at']);

            // The appointment must belong to the same patient.
            $table->foreign(['appointment_id', 'patient_id'])
                ->references(['id', 'patient_id'])->on('appointments')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_number_format CHECK (visit_number ~ '^V-[0-9]{8}-[0-9]{4,}$')");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_type_valid CHECK (visit_type IN ('walk_in', 'appointment'))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_type_matches_appointment CHECK ((visit_type = 'appointment') = (appointment_id IS NOT NULL))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_valid CHECK (status IN ('checked_in', 'in_progress', 'completed', 'cancelled', 'left_without_service'))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_completed_consistent CHECK ((status = 'completed') = (completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_cancelled_consistent CHECK ((status IN ('cancelled', 'left_without_service')) = (cancelled_at IS NOT NULL))");

        // A patient has at most one open visit: prevents duplicate check-ins under concurrency.
        DB::statement("CREATE UNIQUE INDEX visits_one_open_per_patient ON visits (patient_id) WHERE status IN ('checked_in', 'in_progress')");
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
