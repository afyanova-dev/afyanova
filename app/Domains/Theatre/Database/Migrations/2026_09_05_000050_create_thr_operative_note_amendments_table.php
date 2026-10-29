<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operative-note amendments (17-theatre-domain.md §81, §216; ADR-152 D-C).
 *
 * Plain, append-only child table — NOT an AggregateRoot (the Room /
 * thr_case_team_assignments / Clinical clin_note_amendments precedent). Each
 * row: amendment text + reason + author + time, per §81; amendment_text /
 * reason are ROW-ONLY (ADR-136 deny-list). `Rls::enableAppendOnly` enforces
 * no-UPDATE / no-DELETE at the DB (the clin_note_amendments precedent).
 *
 * `version` is SHAPE / CONFORMANCE-ONLY. ADR-152 D-C was self-contradictory
 * (it cited the clin_note_amendments precedent, which carries `version`, AND
 * said "no version — matches enc_encounter_relationships", which does not).
 * BLOCKER resolved by conservative interpretation + rajani ratification
 * (2026-09-05, recorded in the ADR-152 Governance section): the column is
 * present ONLY to satisfy the tenant-scoped-table invariant enforced by
 * ConformanceCommand (IC §9.1 / §10 + check 3b) and stays 0 for the row's
 * whole life. The amendment rows do NOT implement optimistic concurrency —
 * they are insert-only. A SHAPE_EXEMPT entry was explicitly rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_operative_note_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('operative_note_id');
            $table->unsignedInteger('sequence');
            $table->text('amendment_text');
            $table->text('reason');
            $table->uuid('created_by_user_id');
            $table->timestampTz('created_at');

            $table->unsignedBigInteger('version')->default(0);

            $table->index(['tenant_id', 'operative_note_id']);
        });

        Rls::enable('thr_operative_note_amendments');
        Rls::enableAppendOnly('thr_operative_note_amendments');
    }

    public function down(): void
    {
        Rls::disable('thr_operative_note_amendments');
        Schema::dropIfExists('thr_operative_note_amendments');
    }
};
