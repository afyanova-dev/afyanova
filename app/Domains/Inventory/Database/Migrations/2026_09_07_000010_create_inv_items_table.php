<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the Item Master catalog: reference data defining
 * a stocked item, separate from the physical stock of it
 * (18-inventory-and-warehousing-domain.md §7, §8, §9; ADR-170 D-C).
 *
 * Tenant-scoped, mutable, integer-versioned reference data — NOT the ADR-132
 * clinical-record shape (no `facility_id` / `patient_id` / `encounter_id` /
 * `created_by_user_id` / `occurred_at` / `recorded_at`: a catalog entry is not
 * a record about a patient — the CP.1 `clin_procedure_definitions` / Lab.1
 * `lab_test_definitions` / Rad.1 `rad_modalities` precedent). `afyanova:conformance`
 * check 3b requires `id` uuid + `tenant_id` uuid + `version` on a tenant-scoped
 * table.
 *
 * Authoring is CONSOLE-ONLY (`inventory:define-item {action=define|update}` —
 * ADR-170 D-C): no HTTP command, no permission key. Full approval / dedup /
 * effective-dating governance is deferred (working name "Inv.0"), exactly as
 * CP.1 → CP.0 / Lab.1 → Lab.0 / Rad.1 → Rad.0.
 *
 * `item_type` (§9) is open-coded normalised-uppercase — the catalog is
 * "extensible". `uom` is the §169 base stock unit (one unit per item in Inv.1;
 * §170 conversion deferred). `track_batch` / `track_serial` / `track_expiry`
 * are the §8 tracking-rule triple: `track_batch` + `track_expiry` are enforced
 * by `ReceiveStock` in Inv.1; `track_serial` is stored per §8 but serial
 * OPERATIONS are deferred (§40 / §41). `track_expiry ⇒ track_batch` (enforced
 * in `inventory:define-item`). NO reorder / safety-stock columns — §328 places
 * those at FACILITY configuration (Inv.7); NO `required_capability_code` — no
 * Inv.1 operation gates on it (ADR-170 D-C).
 *
 * `code` is tenant-unique among ACTIVE rows only (a retired code may be reused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');                 // e.g. PARACETAMOL-500 — tenant-unique WHERE active
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // §179 — suggested, not enforced
            $table->string('item_type');            // §9 — open-coded normalised uppercase
            $table->string('uom');                  // §169 base stock unit

            $table->boolean('track_batch')->default(false);
            $table->boolean('track_serial')->default(false);  // §8 — stored; serial operations deferred
            $table->boolean('track_expiry')->default(false);  // track_expiry ⇒ track_batch (console-enforced)

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);   // bumped by inventory:define-item update (console)

            $table->timestampsTz();
        });

        // Tenant-unique code among ACTIVE items only — a retired code may be
        // reused by a later item (ADR-170 D-C; the CP.1 / Lab.1 / Rad.1 precedent).
        DB::statement(
            'create unique index inv_items_tenant_code_active_unique '.
            'on inv_items (tenant_id, code) where active'
        );

        Rls::enable('inv_items');
    }

    public function down(): void
    {
        Rls::disable('inv_items');
        Schema::dropIfExists('inv_items');
    }
};
