<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — one requested item/service on an RFQ
 * (19-procurement-domain.md §30, §52; ADR-173 D-B).
 *
 * Plain Eloquent child — NOT an AggregateRoot (the `pcr_requisition_lines` /
 * `pcr_po_lines` precedent). Written directly by `CreateRfqHandler`.
 * `item_code` / `item_name` are SNAPSHOTS from `InventoryApi::getItem` at create
 * time (STOCK lines only — the `ProcurementContextResolver` path). `version` is
 * shape/conformance-only — stays 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_rfq_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->foreignUuid('rfq_id')->constrained('pcr_rfqs');
            $table->unsignedInteger('line_no');
            $table->string('line_type');                        // STOCK | SERVICE

            $table->uuid('item_ref')->nullable();               // no cross-prefix FK — validated via InventoryApi
            $table->string('item_code')->nullable();            // snapshot
            $table->string('item_name')->nullable();            // snapshot
            $table->text('description')->nullable();            // required for SERVICE

            $table->decimal('quantity', 18, 4);
            $table->string('uom');
            $table->date('required_date')->nullable();

            $table->unsignedBigInteger('version')->default(0);  // shape/conformance-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'rfq_id', 'line_no']);
            $table->index(['tenant_id', 'rfq_id']);
        });

        Rls::enable('pcr_rfq_lines');
    }

    public function down(): void
    {
        Rls::disable('pcr_rfq_lines');
        Schema::dropIfExists('pcr_rfq_lines');
    }
};
