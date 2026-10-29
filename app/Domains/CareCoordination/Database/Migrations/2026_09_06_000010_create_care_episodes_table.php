<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Care Episodes (26-care-coordination-domain.md §6, §8; ADR-153 D-B) — the
 * longitudinal container that links encounters into one care journey. Reuses
 * the ADR-132 context+time shape.
 *
 * `facility_id` is the ORIGINATING context (where the episode was opened,
 * like `care_referrals.facility_id`); an episode may span facilities of the
 * tenant (§14), so this column is provenance, not a scope fence — a single
 * row is visible tenant-wide (RLS is tenant-scoped, the Patient MPI / CC.1
 * Referral precedent).
 *
 * `type` is open-coded, normalised uppercase (the `priority` / `ward_type`
 * convention — §162's `CUSTOM` value + facility-defined programmes rule out a
 * hard enum). `override_group` is the §7.4 discriminator (D-D / D-J): NULL
 * for the default episode, a distinct uuidv7 for an explicit audited
 * override.
 *
 * INVARIANT 7.4: at most one OPEN episode of the same (type, subtype) per
 * patient. Enforced by the partial unique index below — the schema builder
 * cannot express a partial index or NULLS NOT DISTINCT, so it is raw SQL
 * (the ADR-121 `enc_encounters` precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_episodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('patient_id');
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->string('status')->default('OPEN');
            $table->uuid('override_group')->nullable();

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        // Invariant 7.4 — one OPEN episode per (patient, type, subtype), unless
        // an explicit override supplies a distinct override_group. NULLS NOT
        // DISTINCT (PG 15+) makes two default (NULL override_group) rows collide.
        DB::statement(
            "CREATE UNIQUE INDEX care_episodes_one_open_per_type
             ON care_episodes (tenant_id, patient_id, type, COALESCE(subtype, ''), override_group)
             NULLS NOT DISTINCT
             WHERE status = 'OPEN'"
        );

        Rls::enable('care_episodes');
    }

    public function down(): void
    {
        Rls::disable('care_episodes');
        Schema::dropIfExists('care_episodes');
    }
};
