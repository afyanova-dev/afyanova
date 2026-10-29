<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — link a stock movement to the goods receipt that posted it.
 *
 * `goods_receipt_ref` is an OPAQUE Inventory document id (no FK) and is
 * nullable so historical movements and non-GRN corrections remain valid.
 * `source_ref` keeps the same value for compatibility; the explicit column
 * makes receipt/GRN queries cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->uuid('goods_receipt_ref')->nullable()->after('po_line_ref');
            $table->index(['tenant_id', 'goods_receipt_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'goods_receipt_ref']);
            $table->dropColumn('goods_receipt_ref');
        });
    }
};
