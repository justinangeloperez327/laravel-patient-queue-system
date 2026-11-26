<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('queue_prefix', 3)->unique();
            $table->string('triage_mode', 16)->default('none');
            $table->foreignId('triage_service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->boolean('allows_appointments')->default(false);
            $table->unsignedSmallInteger('estimated_duration_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Target for composite foreign keys that keep counter/service pairs in one department.
            $table->unique(['id', 'department_id']);
            $table->index('triage_service_id');
        });

        DB::statement("ALTER TABLE services ADD CONSTRAINT services_queue_prefix_format CHECK (queue_prefix ~ '^[A-Z]{1,3}$')");
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_triage_mode_valid CHECK (triage_mode IN ('none', 'optional', 'required'))");
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_triage_target_matches_mode CHECK ((triage_mode = 'none') = (triage_service_id IS NULL))");
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_triage_not_self CHECK (triage_service_id IS NULL OR triage_service_id <> id)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_estimated_duration_range CHECK (estimated_duration_minutes BETWEEN 1 AND 480)');
        DB::statement('CREATE UNIQUE INDEX services_department_name_lower_unique ON services (department_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
