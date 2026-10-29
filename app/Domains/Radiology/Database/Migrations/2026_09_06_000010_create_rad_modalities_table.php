<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (increment Rad.1) — the imaging modality catalog: reference data
 * describing an imaging technology / examination method, separate from the
 * patient-facing imaging SERVICE offered on it (15-radiology-domain.md §11,
 * §13, §185, §187; ADR-165 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no `facility_id` / `patient_id` / `encounter_id` /
 * `created_by_user_id` / `occurred_at` / `recorded_at`: a catalog entry is not
 * a record about a patient — the CP.1 `clin_procedure_definitions` / Lab.1
 * `lab_test_definitions` precedent). `afyanova:conformance` check 3b requires
 * `id` uuid + `tenant_id` uuid + `version` on a tenant-scoped table.
 *
 * Authoring is CONSOLE-ONLY (`radiology:define-modality {action=define|update}`
 * — ADR-165 D-C): no HTTP command, no permission key. Full approval /
 * maker-checker / effective-dating governance is deferred (working name
 * "Rad.0"). `radiology:define-modality update` bumps `version` in place.
 *
 * `operational_status` (§139 / §198 / §209 Inv 31) is open-coded
 * normalised-uppercase; Rad.1 blocks ordering when it is `SUSPENDED` or when
 * `active = false`. `required_capability_code` is checked via
 * `App\Shared\Capability\FacilityCapabilities::has()` at `CreateImagingOrder`
 * (the CP.1 `RequestProcedure` precedent) — NULL means no capability gate.
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_modalities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                                  // e.g. XR, CT, MRI — tenant-unique WHERE active
            $table->string('name');
            $table->string('category')->nullable();                  // §13 — suggested, not enforced
            $table->text('description')->nullable();

            $table->string('required_capability_code')->nullable();  // FacilityCapabilities::has gate; NULL = no gate
            $table->string('operational_status')->default('ACTIVE'); // §139 / §198 — open-coded normalised uppercase

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);          // bumped by radiology:define-modality update (console)

            $table->timestampsTz();
        });

        // Tenant-unique code among ACTIVE modalities only — a retired code may be
        // reused by a later modality (ADR-165 D-C; the CP.1 / Lab.1 precedent).
        DB::statement(
            'create unique index rad_modalities_tenant_code_active_unique '.
            'on rad_modalities (tenant_id, code) where active'
        );

        Rls::enable('rad_modalities');
    }

    public function down(): void
    {
        Rls::disable('rad_modalities');
        Schema::dropIfExists('rad_modalities');
    }
};
