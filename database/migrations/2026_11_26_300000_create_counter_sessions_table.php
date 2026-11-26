<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counter_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE counter_sessions ADD CONSTRAINT counter_sessions_closed_after_opened CHECK (closed_at IS NULL OR closed_at >= opened_at)');
        // One open session per counter and one open counter per staff member.
        DB::statement('CREATE UNIQUE INDEX counter_sessions_one_open_per_counter ON counter_sessions (counter_id) WHERE closed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX counter_sessions_one_open_per_user ON counter_sessions (user_id) WHERE closed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_sessions');
    }
};
