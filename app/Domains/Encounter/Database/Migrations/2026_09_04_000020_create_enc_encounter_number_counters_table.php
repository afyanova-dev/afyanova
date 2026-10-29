<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (increment E.1) — tenant-scoped encounter-number counter (ADR-115).
 *
 * Exactly one row per tenant (`UNIQUE (tenant_id)`). Not part of the Encounter
 * aggregate; allocated by App\Domains\Encounter\Infrastructure\Sequence\
 * EncounterNumberSequence inside the CreateEncounter command transaction.
 * Same IC §10 tenant-only shape as `pat_patient_number_counters` /
 * `org_organizations` — id uuid primary, tenant_id indexed, version,
 * timestampsTz, Rls::enable. `version` is bumped by the manual UPDATE, not
 * HasVersionColumn (the counter is not an aggregate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enc_encounter_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('enc_encounter_number_counters');
    }

    public function down(): void
    {
        Rls::disable('enc_encounter_number_counters');
        Schema::dropIfExists('enc_encounter_number_counters');
    }
};
