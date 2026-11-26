<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * btree_gist lets exclusion constraints combine equality on scalar columns
 * with range overlap (provider schedules, later appointments).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Left installed: other objects may depend on it.
    }
};
