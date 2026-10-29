<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Care Episode ↔ Encounter membership links (26-care-coordination-domain.md
 * §6, §8; ADR-153 D-B). Its OWN AggregateRoot (the `ReferralFeedback` /
 * `pat_patient_relationships` precedent) — it has a real LINKED → DETACHED
 * lifecycle and its own two events, and §7.8 decouples it from the episode's
 * lifecycle.
 *
 * `care_episode_id` carries an intra-domain FK to `care_episodes` (both
 * `care_`, IC §10 permits intra-prefix FKs — the `clin_note_amendments`
 * precedent). `encounter_id` / `patient_id` are plain uuids, NO FK
 * (cross-prefix). `patient_id` is denormalised from the episode for the
 * consistency check and the read models. `role` / `added_reason` /
 * `detach_reason` are ROW-ONLY (ADR-136 deny-list).
 *
 * Detach is a status transition, never a hard delete (§256). One ACTIVE link
 * per (episode, encounter) — the partial unique index below; a DETACHED link
 * does not block re-linking (§7.3 many-to-many is across DIFFERENT episodes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_episode_encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('care_episode_id')->constrained('care_episodes'); // intra-domain FK (IC §10)
            $table->uuid('encounter_id');   // denormalised, NO FK (cross-prefix)
            $table->uuid('patient_id');     // denormalised from the episode, NO FK

            $table->string('role')->nullable();
            $table->string('status')->default('LINKED');

            $table->timestampTz('added_at');
            $table->uuid('added_by_user_id');
            $table->text('added_reason')->nullable();
            $table->timestampTz('detached_at')->nullable();
            $table->uuid('detached_by_user_id')->nullable();
            $table->text('detach_reason')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'care_episode_id', 'status']);
            $table->index(['tenant_id', 'encounter_id', 'status']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX care_episode_encounters_one_active_link
             ON care_episode_encounters (tenant_id, care_episode_id, encounter_id)
             WHERE status = 'LINKED'"
        );

        Rls::enable('care_episode_encounters');
    }

    public function down(): void
    {
        Rls::disable('care_episode_encounters');
        Schema::dropIfExists('care_episode_encounters');
    }
};
