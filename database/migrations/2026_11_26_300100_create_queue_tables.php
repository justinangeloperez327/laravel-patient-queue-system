<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-service, per-clinic-day counter; incremented with an atomic upsert.
        Schema::create('queue_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->date('queue_date');
            $table->unsignedInteger('last_number');
            $table->timestampsTz();

            $table->unique(['service_id', 'queue_date']);
        });

        DB::statement('ALTER TABLE queue_sequences ADD CONSTRAINT queue_sequences_last_number_positive CHECK (last_number > 0)');

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('counter_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_provider_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('served_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('transferred_from_id')->nullable()->unique()->constrained('queue_tickets')->restrictOnDelete();
            // Stored as issued (prefix at that time) so history survives prefix changes.
            $table->string('ticket_number', 16);
            $table->unsignedInteger('sequence_number');
            $table->date('queue_date');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('priority_reason', 255)->nullable();
            $table->string('scheduling_preference', 16)->default('standard');
            $table->string('status', 16)->default('waiting');
            $table->foreignId('issued_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('queued_at');
            $table->timestampTz('eligible_at');
            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['service_id', 'queue_date', 'sequence_number']);
            $table->index(['service_id', 'queue_date', 'status']);
        });

        $statements = [
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_number_format CHECK (ticket_number ~ '^[A-Z]{1,3}-[0-9]{3,}$')",
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_sequence_positive CHECK (sequence_number > 0)',
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_status_valid CHECK (status IN ('waiting', 'called', 'serving', 'skipped', 'completed', 'transferred', 'cancelled'))",
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_scheduling_preference_valid CHECK (scheduling_preference IN ('standard', 'appointment'))",
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_priority_range CHECK (priority BETWEEN 0 AND 2)',
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_priority_reason CHECK (priority = 0 OR priority_reason IS NOT NULL)',
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_eligible_after_queued CHECK (eligible_at >= queued_at)',
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_not_own_predecessor CHECK (transferred_from_id IS NULL OR transferred_from_id <> id)',
            // A called or serving ticket is always at a counter.
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_counter_when_at_counter CHECK (status NOT IN ('called', 'serving') OR counter_id IS NOT NULL)",
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_called_at_present CHECK (status NOT IN ('called', 'serving', 'completed', 'transferred') OR called_at IS NOT NULL)",
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_started_at_present CHECK ((started_at IS NOT NULL) = (status IN ('serving', 'completed', 'transferred')))",
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_completed_at_present CHECK ((completed_at IS NOT NULL) = (status IN ('completed', 'transferred')))",
            "ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_served_by_present CHECK (status NOT IN ('serving', 'completed', 'transferred') OR served_by IS NOT NULL)",
            // One unresolved ticket per visit and service.
            "CREATE UNIQUE INDEX queue_tickets_one_unresolved_per_visit_service ON queue_tickets (visit_id, service_id) WHERE status IN ('waiting', 'called', 'serving', 'skipped')",
            // A counter holds at most one called or serving ticket.
            "CREATE UNIQUE INDEX queue_tickets_one_active_per_counter ON queue_tickets (counter_id) WHERE status IN ('called', 'serving')",
            // Call-next candidate lookup.
            "CREATE INDEX queue_tickets_waiting_lookup ON queue_tickets (service_id, queue_date, priority DESC, eligible_at, sequence_number) WHERE status = 'waiting'",
        ];

        foreach ($statements as $statement) {
            DB::statement($statement);
        }

        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_ticket_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->foreignId('counter_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE queue_events ADD CONSTRAINT queue_events_type_valid CHECK (event_type IN ('issued', 'called', 'recalled', 'started', 'skipped', 'requeued', 'completed', 'transferred', 'cancelled', 'priority_changed'))");
        // Replaying a request with the same idempotency key must not repeat it.
        DB::statement("CREATE UNIQUE INDEX queue_events_idempotency_key_unique ON queue_events ((metadata->>'idempotency_key')) WHERE jsonb_exists(metadata, 'idempotency_key')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION queue_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'queue_events is append-only' USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER queue_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON queue_events
                FOR EACH ROW EXECUTE FUNCTION queue_events_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_events');
        DB::unprepared('DROP FUNCTION IF EXISTS queue_events_append_only()');
        Schema::dropIfExists('queue_tickets');
        Schema::dropIfExists('queue_sequences');
    }
};
