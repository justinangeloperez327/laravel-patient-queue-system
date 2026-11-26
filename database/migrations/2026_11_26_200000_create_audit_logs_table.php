<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal append-only audit trail, introduced in phase 03 because
 * rescheduling must be audited (docs/WORKFLOW.md). Phase 08 reviews it and
 * adds read auditing. Metadata must never contain patient identifiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('outcome', 16)->default('success');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_outcome_valid CHECK (outcome IN ('success', 'denied', 'failure'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only' USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
