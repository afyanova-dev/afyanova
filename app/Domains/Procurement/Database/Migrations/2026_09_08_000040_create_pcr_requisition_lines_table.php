<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — one requested item/service on a purchase
 * requisition (19-procurement-domain.md §18, §52, §53, §244; ADR-171 D-E).
 *
 * Plain Eloquent child — NOT an AggregateRoot (no requisition-line event is
 * named; the `lab_order_items` / `rad_imaging_order_items` / `inv_stock_movements`
 * -adjacent precedent). Written directly by `CreatePurchaseRequisitionHandler`.
 * `item_code` / `item_name` are SNAPSHOTS from `InventoryApi::getItem` at create
 * time (STOCK lines only). `version` is shape/conformance-only — stays 0 (the
 * standing `governance-rulings` interpretation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_requisition_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('requisition_id')->constrained('pcr_purchase_requisitions'); // intra-pcr_ FK
            $table->unsignedInteger('line_no');
            $table->string('line_type');                        // STOCK | SERVICE — open-coded

            $table->uuid('item_ref')->nullable();               // no cross-prefix FK — validated via InventoryApi
            $table->string('item_code')->nullable();            // snapshot
            $table->string('item_name')->nullable();            // snapshot
            $table->text('description')->nullable();            // required for SERVICE

            $table->decimal('quantity', 18, 4);
            $table->string('uom');
            $table->date('required_date')->nullable();

            $table->string('status')->default('OPEN');          // OPEN | CANCELLED

            $table->unsignedBigInteger('version')->default(0);  // shape/conformance-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'requisition_id', 'line_no']);
            $table->index(['tenant_id', 'requisition_id']);
        });

        Rls::enable('pcr_requisition_lines');
    }

    public function down(): void
    {
        Rls::disable('pcr_requisition_lines');
        Schema::dropIfExists('pcr_requisition_lines');
    }
};
