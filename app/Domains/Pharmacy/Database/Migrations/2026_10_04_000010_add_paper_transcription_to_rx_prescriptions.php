<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pharm.4 (paper-transcription origination): a transcribed paper prescription
 * has NO clinical prescribing decision, so `prescribing_decision_id` becomes
 * nullable and the idempotency backstop becomes partial (electronic rows only).
 * Provenance columns record the paper source — never a fake decision link.
 *
 * Idempotent: the 2026-09-22 predecessor of this migration already applied
 * these operations on existing databases (its file was re-dated, not
 * re-authored), so every step skips work that is already in place. Fresh
 * databases apply the full change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('rx_prescriptions', 'source')) {
                $table->string('source')->default('ELECTRONIC'); // ELECTRONIC | PAPER_TRANSCRIPTION | EXTERNAL_PAPER
            }
            if (! Schema::hasColumn('rx_prescriptions', 'prescriber_name')) {
                $table->text('prescriber_name')->nullable();    // paper prescriber, verbatim (paper only)
            }
            if (! Schema::hasColumn('rx_prescriptions', 'transcribed_by_user_id')) {
                $table->uuid('transcribed_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('rx_prescriptions', 'transcription_note')) {
                $table->text('transcription_note')->nullable();
            }
        });

        DB::statement('ALTER TABLE rx_prescriptions ALTER COLUMN prescribing_decision_id DROP NOT NULL');
        DB::statement('ALTER TABLE rx_prescriptions DROP CONSTRAINT IF EXISTS rx_prescriptions_tenant_id_prescribing_decision_id_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS rx_prescriptions_tenant_decision_unique
                 ON rx_prescriptions (tenant_id, prescribing_decision_id)
              WHERE prescribing_decision_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rx_prescriptions_tenant_decision_unique');
        Schema::table('rx_prescriptions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'prescribing_decision_id']);
            foreach (['source', 'prescriber_name', 'transcribed_by_user_id', 'transcription_note'] as $column) {
                if (Schema::hasColumn('rx_prescriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        DB::statement('ALTER TABLE rx_prescriptions ALTER COLUMN prescribing_decision_id SET NOT NULL');
    }
};
