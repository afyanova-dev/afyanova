<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (increment Lab.1) — the laboratory test catalog: reference data
 * describing WHAT a laboratory investigation is, separate from the ORDER for it
 * and the RESULT it produces (14-laboratory-domain.md §12–§17, §124–§125, §191;
 * ADR-157 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no `facility_id` / `patient_id` / `encounter_id` /
 * `created_by_user_id` / `occurred_at` / `recorded_at`: a catalog entry is not a
 * record about a patient — the CP.1 `clin_procedure_definitions` precedent,
 * ADR-146 D-D). `afyanova:conformance` check 3b requires `id` uuid + `tenant_id`
 * uuid + `version` on a tenant-scoped table, which this satisfies.
 *
 * NOT immutable / NOT superseding: `laboratory:define-test update` (a CONSOLE
 * command only in Lab.1 — ADR-157 D-C, no HTTP command, no permission key) bumps
 * `version` in place. Historical integrity is the ORDER ITEM's
 * `test_definition_version` snapshot (`lab_order_items.test_definition_version`),
 * not a versioned definition table (§17).
 *
 * `required_capability_code` is checked via `App\Shared\Capability\
 * FacilityCapabilities::has()` at `CreateLabOrder` (§18, §115, §189 Inv 26–27;
 * ADR-120 — the CP.1 `RequestProcedure` precedent) — NULL means no capability
 * gate. `availability_status` (§20) is open-coded normalized-uppercase; Lab.1
 * blocks ordering only on `DISABLED` or `active = false`, leaving
 * `TEMPORARILY_UNAVAILABLE` / `REFERRAL_ONLY` refinement to a later increment.
 * `specimen_type` / `container_type` (§28–§30) and `parameters` (§13 —
 * `[{code, name, unit}]`) are documentation-only in Lab.1; the specimen and
 * result increments consume them.
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                                  // open, tenant-unique WHERE active
            $table->string('name');
            $table->string('category')->nullable();                  // §10 service categories — suggested, not enforced
            $table->text('description')->nullable();

            $table->string('specimen_type')->nullable();             // §28–§29 — open vocab, documentation-only in Lab.1
            $table->string('container_type')->nullable();            // §30 — open vocab, documentation-only in Lab.1
            $table->jsonb('parameters')->nullable();                 // §13 — [{code, name, unit}]; documentation-only in Lab.1

            $table->string('required_capability_code')->nullable();  // FacilityCapabilities::has gate; NULL = no gate
            $table->string('availability_status')->default('AVAILABLE'); // §20 — open-coded normalized uppercase
            $table->unsignedInteger('turnaround_target_minutes')->nullable(); // §69

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);          // bumped by laboratory:define-test update (console)

            $table->timestampsTz();
        });

        // Tenant-unique code among ACTIVE definitions only — a retired code may
        // be reused by a later definition (ADR-157 D-C; the CP.1 precedent).
        DB::statement(
            'create unique index lab_test_definitions_tenant_code_active_unique '.
            'on lab_test_definitions (tenant_id, code) where active'
        );

        Rls::enable('lab_test_definitions');
    }

    public function down(): void
    {
        Rls::disable('lab_test_definitions');
        Schema::dropIfExists('lab_test_definitions');
    }
};
