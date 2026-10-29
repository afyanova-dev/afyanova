<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — one line on a purchase order
 * (19-procurement-domain.md §51, §52, §53; ADR-171 D-G).
 *
 * Plain Eloquent child — NOT an AggregateRoot. `item_code` / `item_name` are
 * SNAPSHOTS from `InventoryApi::getItem` (STOCK lines). `unit_price` is `Money`
 * (decimal, scale 2). **NO stored `line_total`** — `ordered_qty * unit_price`
 * is computed in the reader/DTO (the Inv.1 `available_qty` lesson; tax/discount
 * §51 deferred). `received_qty` is DECLARED for Proc.1b and updated ONLY by
 * that increment's listener (Inv 15) — it stays 0 in Proc.1a. `version` is
 * shape/conformance-only, stays 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_po_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('po_id')->constrained('pcr_purchase_orders');
            $table->unsignedInteger('line_no');
            $table->string('line_type');                        // STOCK | SERVICE

            $table->uuid('item_ref')->nullable();               // no FK
            $table->string('item_code')->nullable();            // snapshot
            $table->string('item_name')->nullable();            // snapshot
            $table->text('description')->nullable();

            $table->decimal('ordered_qty', 18, 4);
            $table->string('uom');
            $table->decimal('unit_price', 18, 2);               // Money
            $table->uuid('delivery_location_ref')->nullable();  // no FK — Inventory owns locations
            $table->date('required_date')->nullable();

            $table->decimal('received_qty', 18, 4)->default(0); // Proc.1b writes this; 0 in Proc.1a
            $table->string('status')->default('OPEN');          // OPEN | CANCELLED (Proc.1a)

            $table->unsignedBigInteger('version')->default(0);  // shape/conformance-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'po_id', 'line_no']);
            $table->index(['tenant_id', 'po_id']);
            $table->index(['tenant_id', 'item_ref']);
        });

        DB::statement('alter table pcr_po_lines add constraint pcr_po_lines_ordered_qty_positive check (ordered_qty > 0)');
        DB::statement('alter table pcr_po_lines add constraint pcr_po_lines_unit_price_nonneg check (unit_price >= 0)');
        DB::statement('alter table pcr_po_lines add constraint pcr_po_lines_received_qty_nonneg check (received_qty >= 0)');

        Rls::enable('pcr_po_lines');
    }

    public function down(): void
    {
        Rls::disable('pcr_po_lines');
        Schema::dropIfExists('pcr_po_lines');
    }
};
