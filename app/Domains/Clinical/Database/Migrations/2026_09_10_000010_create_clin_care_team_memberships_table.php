<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment C.6) — the clinical care team: one staff member holding
 * one role on one encounter for one bounded period, plus the encounter's
 * provider-of-record designation (12-clinical-domain.md §4.1, §162; ADR-130,
 * ADR-131, ADR-132, ADR-135, ADR-142).
 *
 * `CareTeamMembership` is the aggregate TYPE; each row is an INDEPENDENT
 * aggregate instance (ADR-142 D-C). There is NO `CareTeam` aggregate root and
 * NO `clin_care_teams` table — "the care team" is the derived collection of
 * an encounter's active rows.
 *
 * ORIGIN (ADR-142 D-B / D-M): `encounter_id` = the ORIGINATING encounter,
 * `facility_id` = the ORIGINATING facility and `patient_id` = the encounter's
 * patient — all taken from the encounter context at `AddCareTeamMember`, never
 * client input (Invariant 12), immutable for the row's life. `facility_id` is
 * the authorization / originating-facility-visibility anchor.
 *
 * `team_role` is an OPEN coded string, set-validated by the write path against
 * the documented `TeamRole` constant list (ADR-142 D-F) — no PHP enum, no
 * `#[TypeScript]`, no DB CHECK. It is IMMUTABLE on a row (a role change is
 * end + add). `is_provider_of_record` is a boolean flag on the membership
 * (ADR-142 D-G); at most one active provider-of-record per encounter — enforced
 * by the partial unique index below (ADR-142 D-T; IC §9.1; the ADR-121
 * `enc_one_active_per_patient_facility` precedent).
 *
 * TEMPORAL (ADR-142 D-I): `active_from` is set once by the server at add;
 * `active_to` is set once on `EndCareTeamMembership` and never rewritten
 * (NULL = ACTIVE). ENDED is terminal. There is NO pre-change history table —
 * the retained ended rows plus the `ProviderOfRecordChanged` event / audit
 * stream ARE the history. `end_reason` is optional membership-end rationale
 * and lives ON THIS ROW ONLY — never in an event, never in audit metadata
 * (ADR-142 D-H / D-R / D-S). There is NO `provider_of_record_reason` column
 * (ADR-142 D-H / reconciliation R-1) and NO `care_episode_id` column (deferred
 * to PHASE 7 — ADR-142 D-B).
 *
 * NO foreign key crosses a table-prefix boundary (IC §10, ADR-093, ADR-135):
 * `encounter_id`, `patient_id`, `staff_user_id`, `added_by_user_id`,
 * `removed_by_user_id` are plain stored uuids with NO database FK. NO self-FK,
 * NO CHECK constraint.
 *
 * RLS is tenant-scoped + FORCE (the `clin_notes` / `clin_problems`
 * precedent — ADR-142 D-M / D-T); the table is NOT append-only — the C.6
 * commands are guarded versioned UPDATEs of `active_to` and
 * `is_provider_of_record`. Facility isolation is app-layer (ADR-142 D-N — the
 * handler / policy refinement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_care_team_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();           // ORIGINATING facility (ADR-142 D-B / D-M), from the encounter context
            $table->uuid('encounter_id');                   // ORIGINATING encounter (ADR-142 D-B), plain uuid, NO FK
            $table->uuid('patient_id');                     // the encounter's patient (ADR-142 D-L), plain uuid, NO FK

            $table->uuid('staff_user_id');                  // the care-team member — validated via IdentityApi::isStaff at add (ADR-142 D-E)
            $table->string('team_role');                    // OPEN coded string, set-validated, IMMUTABLE (ADR-142 D-F)
            $table->boolean('is_provider_of_record')->default(false); // ADR-142 D-G — <= 1 active per encounter (partial unique index)

            $table->timestampTz('active_from');             // server now() at add; set once (ADR-142 D-I)
            $table->timestampTz('active_to')->nullable();   // set once on End; never rewritten; NULL = ACTIVE (ADR-142 D-I)
            $table->uuid('added_by_user_id');               // the actor who added the member (ADR-142 §4.1 added_by)
            $table->uuid('removed_by_user_id')->nullable(); // the actor who ended the membership; set once with active_to
            $table->text('end_reason')->nullable();         // optional membership-end rationale — ROW ONLY (ADR-142 D-H)

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'encounter_id']);   // "an encounter's care team (all rows)"
            $table->index(['tenant_id', 'staff_user_id']);  // "an encounter's memberships for a staff member"
        });

        // "an encounter's ACTIVE care team" — the common read path (ADR-142 D-P).
        DB::statement(
            'CREATE INDEX clin_care_team_active_by_encounter
             ON clin_care_team_memberships (tenant_id, encounter_id)
             WHERE active_to IS NULL'
        );

        // ADR-142 D-G / D-T (IC §9.1) — at most one ACTIVE provider-of-record per (tenant, encounter).
        DB::statement(
            'CREATE UNIQUE INDEX clin_one_provider_of_record_per_encounter
             ON clin_care_team_memberships (tenant_id, encounter_id)
             WHERE is_provider_of_record IS TRUE AND active_to IS NULL'
        );

        // ADR-142 D-D / D-T (reconciliation R-3) — no SIMULTANEOUS active duplicate
        // membership for the same (tenant, encounter, staff, role). Historical
        // recurrence after active_to is set REMAINS allowed (ended rows are
        // outside the predicate); a different team_role is a different tuple.
        DB::statement(
            'CREATE UNIQUE INDEX clin_one_active_membership_per_encounter_staff_role
             ON clin_care_team_memberships (tenant_id, encounter_id, staff_user_id, team_role)
             WHERE active_to IS NULL'
        );

        Rls::enable('clin_care_team_memberships');
    }

    public function down(): void
    {
        Rls::disable('clin_care_team_memberships');
        Schema::dropIfExists('clin_care_team_memberships');
    }
};
