<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encounter-to-encounter relationships (11-encounter-domain.md §172;
 * ADR-151 D-C) — closes a real gap: §172 names 5 relationship types
 * (FOLLOW_UP_OF, READMISSION_OF, TRANSFERRED_FROM, REFERRED_FROM,
 * RELATED_TO) but no mechanism existed in the built Encounter model or
 * migrations at all. `relationship_type` is open-coded (not hardcoded to
 * REFERRED_FROM alone), justified directly by §172's own multi-type list.
 *
 * Plain child table — NOT an AggregateRoot (mirrors the Room /
 * thr_case_team_assignments precedent; no relationship-specific event is
 * named anywhere in canon). Insert-only in practice.
 *
 * `version` is SHAPE / CONFORMANCE-ONLY. ADR-151 D-C's column list omitted it
 * while citing the thr_case_team_assignments precedent (which carries it) —
 * the same self-contradiction ADR-152 D-C had. BLOCKER resolved by the
 * standing conservative interpretation, ratified 2026-09-05 (ADR-151
 * Governance note): keep the column to satisfy the tenant-scoped-table
 * invariant enforced by ConformanceCommand (check 3b); do NOT add a
 * SHAPE_EXEMPT entry. It stays 0 for the row's whole life and is not an
 * optimistic-concurrency mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enc_encounter_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('encounter_id');
            $table->uuid('related_encounter_id');
            $table->string('relationship_type');
            $table->uuid('created_by_user_id');
            $table->timestampTz('created_at');

            $table->unsignedBigInteger('version')->default(0);

            $table->index(['tenant_id', 'encounter_id']);
            $table->index(['tenant_id', 'related_encounter_id']);
        });

        Rls::enable('enc_encounter_relationships');
    }

    public function down(): void
    {
        Rls::disable('enc_encounter_relationships');
        Schema::dropIfExists('enc_encounter_relationships');
    }
};
