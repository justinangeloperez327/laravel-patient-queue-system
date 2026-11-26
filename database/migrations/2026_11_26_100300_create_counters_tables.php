<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('location', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['id', 'department_id']);
        });

        DB::statement("ALTER TABLE counters ADD CONSTRAINT counters_code_format CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]*$')");

        // department_id is denormalised so both composite foreign keys can
        // guarantee that a counter only serves services of its own department.
        Schema::create('counter_services', function (Blueprint $table) {
            $table->foreignId('counter_id');
            $table->foreignId('service_id')->index();
            $table->foreignId('department_id');

            $table->primary(['counter_id', 'service_id']);

            $table->foreign(['counter_id', 'department_id'])
                ->references(['id', 'department_id'])->on('counters')
                ->restrictOnDelete();
            $table->foreign(['service_id', 'department_id'])
                ->references(['id', 'department_id'])->on('services')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_services');
        Schema::dropIfExists('counters');
    }
};
