<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (increment Rad.1) — the imaging service catalog: the patient-facing
 * imaging examination offering, performed on a modality (15-radiology-domain.md
 * §11–§12, §185, §187; ADR-165 D-D).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (the CP.1 / Lab.1 catalog precedent). Authoring is
 * CONSOLE-ONLY (`radiology:define-imaging-service {action=define|update}` with
 * `--modality <code>` — ADR-165 D-D): no HTTP command, no permission key.
 *
 * `modality_id` is an intra-`rad_` FK to `rad_modalities`. `body_site` /
 * `default_laterality` / `contrast_requirement` are open-coded, normalised
 * uppercase, DOCUMENTATION-ONLY in Rad.1 (the study/report increments consume
 * them). `preparation_text` is free text, ROW-ONLY (§110 — never event / audit
 * metadata, the ADR-136 deny-list). `referral_only` (§84 / §138 / §209 Inv 32)
 * marks a service that is offered but not performed internally — Rad.1 blocks
 * internal ordering of it (the referral routing path is deferred to a later
 * increment). `availability_status` (§137) is open-coded; Rad.1 blocks ordering
 * on `DISABLED` or `active = false`.
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_imaging_services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                                  // e.g. XR-CHEST — tenant-unique WHERE active
            $table->string('name');
            $table->foreignUuid('modality_id')->constrained('rad_modalities'); // intra-rad_ FK

            $table->string('body_site')->nullable();                 // §14 — structured vocab, documentation-only in Rad.1
            $table->string('default_laterality')->nullable();        // §15 — documentation-only in Rad.1
            $table->string('contrast_requirement')->default('NONE'); // §16 — NONE|OPTIONAL|REQUIRED seed, documentation-only in Rad.1
            $table->text('preparation_text')->nullable();            // §110 — free text, row-only (ADR-136)

            $table->unsignedInteger('duration_minutes')->nullable();          // §12
            $table->unsignedInteger('turnaround_target_minutes')->nullable(); // §12 / §79

            $table->string('required_capability_code')->nullable();  // FacilityCapabilities::has gate; NULL = no gate
            $table->boolean('referral_only')->default(false);        // §84 / §138 — offered, not performed internally
            $table->string('availability_status')->default('AVAILABLE'); // §137 — open-coded normalised uppercase

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);          // bumped by radiology:define-imaging-service update (console)

            $table->timestampsTz();

            $table->index(['tenant_id', 'modality_id']);
        });

        DB::statement(
            'create unique index rad_imaging_services_tenant_code_active_unique '.
            'on rad_imaging_services (tenant_id, code) where active'
        );

        Rls::enable('rad_imaging_services');
    }

    public function down(): void
    {
        Rls::disable('rad_imaging_services');
        Schema::dropIfExists('rad_imaging_services');
    }
};
