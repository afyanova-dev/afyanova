<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surgical specimens (17-theatre-domain.md §72–§74, §131, §176–§177, §237,
 * §289; ADR-164 D-A) — tissue / samples removed during a surgical case and
 * handed off to the laboratory. Multi-per-case.
 *
 * **Theatre retains its own specimen identity; Laboratory LATER assigns the
 * lab accession (§177).** `lab_accession_ref` is a nullable OPAQUE string with
 * NO FK — the CP.1 provenance-pointer precedent; recorded by
 * `MarkSurgicalSpecimenReceived` once the lab confirms receipt. There is NO
 * cross-domain call — Laboratory (Lab.2's `lab_specimens`) stays frozen; a
 * real Theatre↔Laboratory event handshake is a future integration increment.
 *
 * `patient_id` is a SNAPSHOT of the case's patient (wrong-patient safety, §237).
 * `specimen_type` / `body_site` / `laterality` / `destination_laboratory` are
 * open-coded (normalised uppercase where coded). `description` is free text,
 * ROW-ONLY — never event / audit metadata (the ADR-136 deny-list).
 *
 * Chain of custody (§74): COLLECTED → LABELED → HANDED_OVER → RECEIVED, via
 * `SurgicalSpecimenLifecycle`. Duplicate handoff is rejected (§318).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_surgical_specimens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->uuid('patient_id'); // snapshot (§237)
            $table->uuid('encounter_id');

            $table->unsignedInteger('sequence'); // per-case (the SurgicalProcedure precedent)
            $table->string('specimen_type');
            $table->string('body_site')->nullable();
            $table->string('laterality')->nullable();
            $table->text('description')->nullable(); // free text, row-only (ADR-136)

            $table->uuid('collected_by_user_id');
            $table->timestampTz('collected_at');

            $table->string('destination_laboratory')->nullable();
            $table->string('lab_accession_ref')->nullable(); // opaque, no FK (§177 — Laboratory assigns later)

            $table->string('status')->default('COLLECTED');
            $table->uuid('handed_over_by_user_id')->nullable();
            $table->timestampTz('handed_over_at')->nullable();
            $table->timestampTz('received_recorded_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'case_id', 'sequence']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('thr_surgical_specimens');
    }

    public function down(): void
    {
        Rls::disable('thr_surgical_specimens');
        Schema::dropIfExists('thr_surgical_specimens');
    }
};
