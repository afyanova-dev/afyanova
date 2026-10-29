<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment CP.1) — the procedure catalog: reference data describing
 * WHAT a procedure is, separate from the instance describing THIS procedure
 * performed on THIS patient (13-clinical-procedures-domain.md §11–§13, §111–§112;
 * ADR-137, ADR-146 D-C / D-D).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no `facility_id` / `patient_id` / `encounter_id` /
 * `author_user_id` / `occurred_at` / `recorded_at`: this is a catalog entry, not
 * a clinical record about a patient — ADR-146 D-D). `afyanova:conformance`'s
 * column-shape check requires only `id` uuid + `tenant_id` uuid + `version` on a
 * tenant-scoped table, which this satisfies.
 *
 * NOT immutable / NOT superseding: `UpdateProcedureDefinition` (a CONSOLE
 * command only in CP.1 — ADR-146 D-C, no HTTP command, no permission key) bumps
 * `version` in place. Historical integrity is the INSTANCE's `definition_version`
 * snapshot (`clin_procedures.definition_version`), not a versioned definition
 * table (13 §112).
 *
 * `required_capability_code` is checked via `App\Shared\Capability\
 * FacilityCapabilities::has()` at `RequestProcedure` (13 §22–§23; ADR-120) — NULL
 * means no capability gate. `requires_site` / `requires_laterality` /
 * `requires_readiness_check` are booleans a later command validates against
 * (ADR-146 D-H / D-F). `material_requirements` is a REQUIREMENTS list (not
 * stock — 13 §51, §114), documentation only in CP.1.
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_procedure_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                                  // open, tenant-unique WHERE active
            $table->string('name');
            $table->string('category')->nullable();                  // 13 §13 categories suggested, not enforced
            $table->text('description')->nullable();

            $table->string('required_capability_code')->nullable();  // FacilityCapabilities::has gate; NULL = no gate
            $table->boolean('requires_site')->default(false);
            $table->boolean('requires_laterality')->default(false);
            $table->boolean('requires_readiness_check')->default(false);
            $table->jsonb('material_requirements')->nullable();      // [{material_ref, default_quantity, unit}]

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);          // bumped by UpdateProcedureDefinition (console)

            $table->timestampsTz();
        });

        // Tenant-unique code among ACTIVE definitions only — a retired code may
        // be reused by a later definition (ADR-146 D-C).
        DB::statement(
            'create unique index clin_procedure_definitions_tenant_code_active_unique '.
            'on clin_procedure_definitions (tenant_id, code) where active'
        );

        Rls::enable('clin_procedure_definitions');
    }

    public function down(): void
    {
        Rls::disable('clin_procedure_definitions');
        Schema::dropIfExists('clin_procedure_definitions');
    }
};
