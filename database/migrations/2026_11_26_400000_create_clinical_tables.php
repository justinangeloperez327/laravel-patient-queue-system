<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical documentation. Signed notes are immutable, amendments and vital
 * signs are append-only (enforced by triggers), and every record's ticket
 * must belong to its visit (composite foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            // Target for (queue_ticket_id, visit_id) composite foreign keys.
            $table->unique(['id', 'visit_id']);
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('queue_ticket_id')->index();
            $table->foreignId('author_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('note_type', 16);
            $table->text('chief_complaint')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->text('additional_notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestampTz('signed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['queue_ticket_id', 'visit_id'])->references(['id', 'visit_id'])->on('queue_tickets')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_type_valid CHECK (note_type IN ('triage', 'nursing', 'soap'))");
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_status_valid CHECK (status IN ('draft', 'signed'))");
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_signed_at_consistent CHECK ((status = 'signed') = (signed_at IS NOT NULL))");
        // One working draft per author, ticket and note type.
        DB::statement("CREATE UNIQUE INDEX clinical_notes_one_draft ON clinical_notes (queue_ticket_id, author_id, note_type) WHERE status = 'draft'");

        Schema::create('clinical_note_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_note_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->index()->constrained('users')->restrictOnDelete();
            $table->text('amendment');
            $table->string('reason', 255);
            $table->timestampTz('signed_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('queue_ticket_id')->index();
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature_celsius', 4, 1)->nullable();
            $table->unsignedSmallInteger('oxygen_saturation')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->unsignedSmallInteger('pain_score')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->foreign(['queue_ticket_id', 'visit_id'])->references(['id', 'visit_id'])->on('queue_tickets')->restrictOnDelete();
        });

        // Units: mmHg, beats/min, breaths/min, °C, %, kg, cm, 0–10 scale.
        foreach ([
            'vital_signs_systolic_range' => 'systolic_bp IS NULL OR systolic_bp BETWEEN 50 AND 300',
            'vital_signs_diastolic_range' => 'diastolic_bp IS NULL OR diastolic_bp BETWEEN 20 AND 200',
            'vital_signs_bp_pair' => '(systolic_bp IS NULL) = (diastolic_bp IS NULL)',
            'vital_signs_bp_order' => 'systolic_bp IS NULL OR diastolic_bp < systolic_bp',
            'vital_signs_heart_rate_range' => 'heart_rate IS NULL OR heart_rate BETWEEN 20 AND 250',
            'vital_signs_respiratory_rate_range' => 'respiratory_rate IS NULL OR respiratory_rate BETWEEN 4 AND 80',
            'vital_signs_temperature_range' => 'temperature_celsius IS NULL OR temperature_celsius BETWEEN 30.0 AND 45.0',
            'vital_signs_oxygen_saturation_range' => 'oxygen_saturation IS NULL OR oxygen_saturation BETWEEN 50 AND 100',
            'vital_signs_weight_range' => 'weight_kg IS NULL OR weight_kg BETWEEN 0.3 AND 500',
            'vital_signs_height_range' => 'height_cm IS NULL OR height_cm BETWEEN 20 AND 260',
            'vital_signs_pain_range' => 'pain_score IS NULL OR pain_score BETWEEN 0 AND 10',
            'vital_signs_not_empty' => 'num_nonnulls(systolic_bp, heart_rate, respiratory_rate, temperature_celsius, oxygen_saturation, weight_kg, height_cm, pain_score) > 0',
        ] as $name => $check) {
            DB::statement("ALTER TABLE vital_signs ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_notes_protect_signed() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'signed' THEN
                    RAISE EXCEPTION 'signed clinical notes are immutable' USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER clinical_notes_signed_immutable
                BEFORE UPDATE OR DELETE ON clinical_notes
                FOR EACH ROW EXECUTE FUNCTION clinical_notes_protect_signed();

            CREATE OR REPLACE FUNCTION clinical_note_amendments_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'clinical note amendments are append-only' USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM clinical_notes WHERE id = NEW.clinical_note_id AND status = 'signed') THEN
                    RAISE EXCEPTION 'only signed clinical notes can be amended' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER clinical_note_amendments_append_only
                BEFORE INSERT OR UPDATE OR DELETE ON clinical_note_amendments
                FOR EACH ROW EXECUTE FUNCTION clinical_note_amendments_guard();

            CREATE OR REPLACE FUNCTION vital_signs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'vital signs are append-only; record a new set to correct a reading' USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER vital_signs_no_update_or_delete
                BEFORE UPDATE OR DELETE ON vital_signs
                FOR EACH ROW EXECUTE FUNCTION vital_signs_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
        Schema::dropIfExists('clinical_note_amendments');
        Schema::dropIfExists('clinical_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS vital_signs_append_only(); DROP FUNCTION IF EXISTS clinical_note_amendments_guard(); DROP FUNCTION IF EXISTS clinical_notes_protect_signed();');

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropUnique(['id', 'visit_id']);
        });
    }
};
