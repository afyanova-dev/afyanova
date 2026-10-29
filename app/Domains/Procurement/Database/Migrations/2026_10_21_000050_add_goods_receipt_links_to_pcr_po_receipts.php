<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — link the Procurement PO receipt projection to the Inventory GRN.
 *
 * A multi-line GRN emits ONE `GoodsReceiptPosted` event and the Procurement
 * projection writes one `pcr_po_receipts` row per accepted receipt line. The
 * old `unique(tenant_id, source_event_id)` would collapse those rows, so
 * idempotency moves to the authoritative receipt line
 * `unique(tenant_id, goods_receipt_line_ref)`.
 *
 * Existing rows are backfilled: the per-line `StockReceived` event id is the
 * legacy line identity. `source_event_id` is retained for provenance and gets
 * a non-unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_po_receipts', function (Blueprint $table): void {
            $table->uuid('goods_receipt_ref')->nullable()->after('po_line_id');
            $table->uuid('goods_receipt_line_ref')->nullable()->after('goods_receipt_ref');
            $table->string('exception_code')->nullable()->after('source_type');
        });

        DB::statement('update pcr_po_receipts set goods_receipt_line_ref = source_event_id where goods_receipt_line_ref is null');

        DB::statement('drop index if exists pcr_po_receipts_tenant_source_event_unique');

        Schema::table('pcr_po_receipts', function (Blueprint $table): void {
            $table->index(['tenant_id', 'source_event_id']);
            $table->index(['tenant_id', 'goods_receipt_ref']);
            $table->unique(['tenant_id', 'goods_receipt_line_ref']);
        });
    }

    public function down(): void
    {
        // Preserve the old uniqueness contract before restoring its index.
        DB::statement('update pcr_po_receipts set source_event_id = goods_receipt_line_ref where goods_receipt_line_ref is not null');

        Schema::table('pcr_po_receipts', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'goods_receipt_line_ref']);
            $table->dropIndex(['tenant_id', 'goods_receipt_ref']);
            $table->dropIndex(['tenant_id', 'source_event_id']);
            $table->dropColumn(['goods_receipt_ref', 'goods_receipt_line_ref', 'exception_code']);
        });

        DB::statement(
            'create unique index pcr_po_receipts_tenant_source_event_unique '.
            'on pcr_po_receipts (tenant_id, source_event_id)'
        );
    }
};
