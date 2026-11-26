<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('patient_number', 20)->unique();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->index();
            $table->string('sex', 16)->nullable();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 255)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->foreignId('registered_by')->index()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_number_format CHECK (patient_number ~ '^PT-[0-9]{6,}$')");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_sex_valid CHECK (sex IS NULL OR sex IN ('female', 'male', 'intersex', 'unknown'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_date_of_birth_plausible CHECK (date_of_birth >= DATE '1900-01-01')");
        DB::statement('CREATE INDEX patients_name_lower_index ON patients (lower(last_name), lower(first_name))');

        // Source of PT-###### numbers; nextval() is atomic and never reused.
        // Owned by the column so it is dropped together with the table.
        DB::statement('CREATE SEQUENCE patient_number_seq START 1 OWNED BY patients.patient_number');
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
