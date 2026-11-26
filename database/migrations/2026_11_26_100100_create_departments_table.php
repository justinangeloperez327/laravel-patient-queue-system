<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_code_format CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]*$')");
        DB::statement('CREATE UNIQUE INDEX departments_name_lower_unique ON departments (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
