<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment CP.1) — tenant-scoped procedure-number counter (ADR-137 —
 * `PROC-%08d`; the `enc_encounter_number_counters` / ADR-115 precedent).
 *
 * Exactly one row per tenant (`UNIQUE (tenant_id)`). Not part of the
 * `ClinicalProcedure` aggregate; allocated by
 * `App\Domains\Clinical\Infrastructure\Sequence\ProcedureNumberSequence` inside
 * the `RequestProcedure` command transaction. Same IC §10 tenant-only shape as
 * `enc_encounter_number_counters` / `pat_patient_number_counters` — id uuid
 * primary, tenant_id indexed, a NOMINAL `version` column (bumped by the manual
 * UPDATE, not `HasVersionColumn` — the counter is not an aggregate), timestampsTz,
 * Rls::enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_procedure_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 pattern — one counter row per tenant
        });

        Rls::enable('clin_procedure_number_counters');
    }

    public function down(): void
    {
        Rls::disable('clin_procedure_number_counters');
        Schema::dropIfExists('clin_procedure_number_counters');
    }
};
