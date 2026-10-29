<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3b) — the PO line → contract line link
 * (19-procurement-domain.md §196–§198, §303 Invariant 33; ADR-177 D-B — **amends
 * ADR-176 D-E by pointer**).
 *
 * `CreatePurchaseOrder(contractId)` records which contract line each PO line
 * consumes. It is the state the cumulative-ceiling invariant (INV-33a) is
 * derived from — `committed` = Σ `ordered_qty` over non-CANCELLED PO lines per
 * `contract_line_id`. NULLABLE — NULL on every non-contract PO line and every
 * pre-Proc.3b row, no backfill. Intra-`pcr_` FK. Runs AFTER
 * `pcr_contract_lines` is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_po_lines', function (Blueprint $table): void {
            $table->foreignUuid('contract_line_id')->nullable()->after('item_ref')->constrained('pcr_contract_lines');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_po_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_line_id');
        });
    }
};
