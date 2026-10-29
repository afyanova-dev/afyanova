<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1b) — the append-only Purchase-Order receipt ledger
 * (19-procurement-domain.md §56, §60, §61, §125–§129; ADR-172 D-E).
 *
 * One row per `StockReceived` event Procurement matched to a PO. The
 * `pcr_po_amendments` / `inv_stock_movements` shape EXACTLY — a plain
 * append-only child, NOT an AggregateRoot; written directly by
 * `RecordPurchaseOrderReceiptHandler` (the system reaction to Inventory's
 * `StockReceived`, Inv 15 — Procurement never writes stock, only projects it).
 * `Rls::enable` + `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE; a
 * correction is a further receipt row). `version` is nominal — stays 0 (the
 * standing `governance-rulings` interpretation).
 *
 * `movement_id` / `movement_number` / `item_ref` / `batch_ref` / `location_ref`
 * are OPAQUE Inventory identifiers — NO `inv_` FK (§53 / §340). `source_event_id`
 * is the `StockReceived` eventId; `unique(tenant_id, source_event_id)` is the
 * DB-level idempotency backstop layered on the `shared_idempotency_keys` claim
 * (ADR-172 D-J). `po_line_id` is nullable — null means the received item is not
 * on any line of the PO (recorded for provenance; no `received_qty` change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_po_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('po_id')->constrained('pcr_purchase_orders');
            $table->foreignUuid('po_line_id')->nullable()->constrained('pcr_po_lines');

            $table->uuid('source_event_id');                 // the StockReceived eventId
            $table->uuid('movement_id');                     // OPAQUE — no inv_ FK
            $table->string('movement_number');               // snapshot
            $table->uuid('item_ref');                        // OPAQUE
            $table->uuid('batch_ref')->nullable();           // OPAQUE
            $table->uuid('location_ref');                    // OPAQUE

            $table->decimal('quantity', 18, 4);
            $table->string('uom');
            $table->string('source_type');                   // the event's sourceType — open-coded

            $table->timestampTz('received_at');              // from the event's occurredAt
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0); // nominal, stays 0
            $table->timestampsTz();

            $table->index(['tenant_id', 'po_id']);
        });

        DB::statement(
            'create unique index pcr_po_receipts_tenant_source_event_unique '.
            'on pcr_po_receipts (tenant_id, source_event_id)'
        );
        DB::statement('alter table pcr_po_receipts add constraint pcr_po_receipts_quantity_positive check (quantity > 0)');

        Rls::enable('pcr_po_receipts');
        Rls::enableAppendOnly('pcr_po_receipts');
    }

    public function down(): void
    {
        Rls::disable('pcr_po_receipts');
        Schema::dropIfExists('pcr_po_receipts');
    }
};
