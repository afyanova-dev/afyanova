<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the immutable Stock Movement ledger
 * (18-inventory-and-warehousing-domain.md §47–§51, §77–§83, §84, §213, §252,
 * §255; ADR-170 D-G).
 *
 * A STANDALONE append-only ledger record — NOT an AggregateRoot, NOT a child
 * of any aggregate (the `clin_procedure_materials` / `lab_order_items` /
 * `rad_report_amendments` shape): handler-written, immutable (Inv 3),
 * `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE; corrections use a
 * compensating movement — Inv 15). The `version` column is a NOMINAL
 * conformance-shape column that stays 0 for the row's life (the standing
 * `governance-rulings` interpretation).
 *
 * `movement_type` (§51) — RECEIPT | CONSUMPTION | ADJUSTMENT active in Inv.1
 * (ISSUE / TRANSFER_* / RETURN / COUNT_VARIANCE / WRITE_OFF / DISPOSAL are
 * later increments). `direction` (IN | OUT) + a positive `quantity` (§326) —
 * the balance delta is `direction == IN ? +quantity : −quantity`; reconciliation
 * sums `CASE direction WHEN 'IN' THEN quantity ELSE −quantity END`.
 * `source_type` (§78 / §159 folded) is open-coded. `source_ref` / `po_ref` are
 * OPAQUE identifiers, NO FK, NO validation in Inv.1 (§77 / §282 / §283).
 * `reason_text` is free text, ROW-ONLY (never event / audit metadata — the
 * ADR-136 deny-list).
 *
 * NO `patient_id` / `encounter_id` — a deliberate §281 / §282 / §283 deviation
 * from the ADR-132 record shape (Inventory keeps procedure / case / dispensing
 * refs as the opaque `source_ref`, never patient identity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();   // = the location's facility

            $table->string('movement_number')->unique(); // MOV-%08d
            $table->string('movement_type');             // §51 — open-coded normalised uppercase
            $table->string('direction');                 // IN | OUT
            $table->decimal('quantity', 18, 4);          // POSITIVE (§326)
            $table->string('uom');                       // snapshot of the item's unit

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->string('source_type');               // §78 / §159 — open-coded
            $table->string('source_ref')->nullable();    // OPAQUE — no FK (§77 / §282)
            $table->string('po_ref')->nullable();        // OPAQUE Procurement PO id — no pcr_ FK (§53 / §340)
            $table->text('reason_text')->nullable();     // free text, ROW-ONLY (ADR-136)

            $table->uuid('actor_user_id');               // §79 — Inv.1 movements are manually initiated
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0); // nominal, stays 0 (governance-rulings)
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'location_id']);
            $table->index(['tenant_id', 'location_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_ref']);
        });

        DB::statement('alter table inv_stock_movements add constraint inv_stock_movements_qty_positive check (quantity > 0)');
        DB::statement("alter table inv_stock_movements add constraint inv_stock_movements_direction check (direction in ('IN', 'OUT'))");

        Rls::enable('inv_stock_movements');
        Rls::enableAppendOnly('inv_stock_movements');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_movements');
        Schema::dropIfExists('inv_stock_movements');
    }
};
