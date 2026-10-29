<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2a) — the Purchase Order sourcing link
 * (19-procurement-domain.md §278, §309; ADR-173 D-N — **amends ADR-171 D-G by
 * pointer**).
 *
 * ADR-171 D-G's frozen `pcr_purchase_orders` column list named only
 * `requisition_id` for sourcing. Proc.2a adds `sourcing_rfq_id` (the award's
 * RFQ) + `sourcing_method` (open-coded — DIRECT | REQUISITION | RFQ). Both
 * NULLABLE, no backfill — NULL on every pre-Proc.2a row; new POs set them
 * explicitly. Additive, non-breaking. Runs AFTER `pcr_rfqs` is created (the FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->foreignUuid('sourcing_rfq_id')->nullable()->after('requisition_id')->constrained('pcr_rfqs');
            $table->string('sourcing_method')->nullable()->after('sourcing_rfq_id'); // DIRECT | REQUISITION | RFQ
        });
    }

    public function down(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sourcing_rfq_id');
            $table->dropColumn('sourcing_method');
        });
    }
};
