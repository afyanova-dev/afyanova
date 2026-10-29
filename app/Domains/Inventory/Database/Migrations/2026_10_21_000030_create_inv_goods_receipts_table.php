<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — one goods-receipt header per physical delivery.
 *
 * The GRN is the operational document: a PO delivery, donation or opening
 * balance arrives once and is posted once. Its lines carry the accepted and
 * rejected quantities; the stock ledger carries one movement per accepted
 * receipt line.
 *
 * Invoice fields are captured for the future finance workflow but are NOT
 * posted to a ledger and contain no VAT/tax fields or calculations.
 *
 * `po_ref` / `supplier_ref` are opaque references (no cross-domain FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('receipt_number'); // GRN-%08d
            $table->string('source_type'); // PROCUREMENT | DONATION | OPENING_BALANCE | OTHER
            $table->string('po_ref')->nullable(); // PO number, opaque
            $table->uuid('supplier_ref')->nullable(); // Supplier id, opaque

            $table->foreignUuid('location_id')->constrained('inv_stock_locations');

            $table->string('delivery_note_number')->nullable();
            $table->text('delivery_note_missing_reason')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_status')->default('RECEIVED'); // RECEIVED | PENDING | NOT_REQUIRED
            $table->string('invoice_currency', 3)->default('TZS');
            $table->decimal('invoice_total', 18, 2)->nullable();

            $table->timestampTz('received_at');
            $table->uuid('received_by_user_id');
            $table->text('remarks')->nullable(); // ROW-ONLY (ADR-136)

            $table->string('status')->default('POSTED'); // POSTED
            $table->string('match_status')->default('UNMATCHED'); // UNMATCHED | MATCHED | VARIANCE
            $table->timestampTz('matched_at')->nullable();
            $table->uuid('matched_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'facility_id', 'received_at']);
            $table->index(['tenant_id', 'po_ref']);
            $table->index(['tenant_id', 'supplier_ref']);
            $table->index(['tenant_id', 'invoice_number']);
        });

        DB::statement("alter table inv_goods_receipts add constraint inv_goods_receipts_source_type_check check (source_type in ('PROCUREMENT', 'DONATION', 'OPENING_BALANCE', 'OTHER'))");
        DB::statement("alter table inv_goods_receipts add constraint inv_goods_receipts_invoice_status_check check (invoice_status in ('RECEIVED', 'PENDING', 'NOT_REQUIRED'))");
        DB::statement("alter table inv_goods_receipts add constraint inv_goods_receipts_status_check check (status in ('POSTED'))");
        DB::statement("alter table inv_goods_receipts add constraint inv_goods_receipts_match_status_check check (match_status in ('UNMATCHED', 'MATCHED', 'VARIANCE'))");
        DB::statement('alter table inv_goods_receipts add constraint inv_goods_receipts_invoice_total_check check (invoice_total is null or invoice_total >= 0)');

        Rls::enable('inv_goods_receipts');
    }

    public function down(): void
    {
        Rls::disable('inv_goods_receipts');
        Schema::dropIfExists('inv_goods_receipts');
    }
};
