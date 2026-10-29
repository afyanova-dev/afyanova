<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — the lines of a goods receipt (GRN).
 *
 * `quantity_accepted` is GENERATED ALWAYS AS (`quantity_delivered` −
 * `quantity_rejected`) so the accepted quantity cannot drift from the two
 * quantities the receiver actually entered. Only accepted quantity posts to
 * stock.
 *
 * `movement_id` is nullable by design: a fully rejected line is still recorded
 * on the GRN, but there is no stock movement to link. `po_line_ref` is the
 * authoritative Procurement line reference (P0-01), never an item-code guess.
 *
 * Batch/expiry fields describe what arrived; `inv_batches` receives the
 * canonical lot row only when accepted quantity is positive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_goods_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('receipt_id')->constrained('inv_goods_receipts')->cascadeOnDelete();

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->uuid('po_line_ref')->nullable(); // exact Procurement line id, opaque
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');
            $table->uuid('movement_id')->nullable(); // stock movement for accepted quantity, opaque

            $table->string('entered_unit');
            $table->decimal('entered_quantity', 18, 4);
            $table->decimal('pack_size', 18, 4);
            $table->decimal('quantity_delivered', 18, 4);
            $table->decimal('quantity_rejected', 18, 4)->default(0);
            $table->decimal('quantity_accepted', 18, 4)->storedAs('quantity_delivered - quantity_rejected');
            $table->string('uom');

            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->string('condition')->default('GOOD'); // GOOD | DAMAGED | SHORT | NEAR_EXPIRY | WRONG_ITEM

            $table->foreignUuid('substituted_for_item_id')->nullable()->constrained('inv_items');
            $table->text('condition_notes')->nullable(); // ROW-ONLY (ADR-136)
            $table->string('rejection_reason')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'receipt_id']);
            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'po_line_ref']);
            $table->unique(['tenant_id', 'receipt_id', 'po_line_ref']);
        });

        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_entered_quantity_check check (entered_quantity > 0)');
        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_pack_size_check check (pack_size > 0)');
        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_delivered_check check (quantity_delivered > 0)');
        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_rejected_check check (quantity_rejected >= 0 and quantity_rejected <= quantity_delivered)');
        DB::statement("alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_condition_check check (condition in ('GOOD', 'DAMAGED', 'SHORT', 'NEAR_EXPIRY', 'WRONG_ITEM'))");

        Rls::enable('inv_goods_receipt_lines');
    }

    public function down(): void
    {
        Rls::disable('inv_goods_receipt_lines');
        Schema::dropIfExists('inv_goods_receipt_lines');
    }
};
