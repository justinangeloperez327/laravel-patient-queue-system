<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('display_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // SHA-256 of the pairing key; the key itself is shown once and never stored.
            $table->char('credential_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            // {"service_ids": [..], "voice_lang": "en-US", "title": "..."}
            $table->jsonb('configuration')->default('{}');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE display_devices ADD CONSTRAINT display_devices_credential_hash_format CHECK (credential_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE display_devices ADD CONSTRAINT display_devices_configuration_object CHECK (jsonb_typeof(configuration) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('display_devices');
    }
};
