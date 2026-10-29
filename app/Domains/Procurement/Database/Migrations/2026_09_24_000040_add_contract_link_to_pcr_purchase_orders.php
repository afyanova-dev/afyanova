<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3a) — the Purchase Order → contract link
 * (19-procurement-domain.md §40, §42, §303 Inv 31; ADR-176 D-E — **amends
 * ADR-171 D-G + ADR-173 D-N by pointer**).
 *
 * `CreatePurchaseOrder` gains `?contractId`; when set, the PO lines are seeded
 * from the contract (contract-priced) and `sourcing_method = 'CONTRACT'`.
 * `contract_id` is a SNAPSHOT FK — a historical PO keeps pointing at the
 * contract row it was created against even after that contract is superseded
 * (Inv 31). NULLABLE — NULL on every pre-Proc.3a row, no backfill. Runs AFTER
 * `pcr_contracts` is created (the FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->foreignUuid('contract_id')->nullable()->after('sourcing_method')->constrained('pcr_contracts');
        });
    }

    public function down(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
