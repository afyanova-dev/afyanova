<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('return_number');
            $table->foreignUuid('receipt_id')->constrained('inv_goods_receipts');
            $table->uuid('supplier_ref')->nullable();
            $table->foreignUuid('location_id')->constrained('inv_stock_locations');
            $table->string('reason');
            $table->string('status');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'return_number']);
            $table->index(['tenant_id', 'facility_id', 'occurred_at']);
            $table->index(['tenant_id', 'receipt_id']);
        });

        DB::statement("alter table inv_supplier_returns add constraint inv_supplier_returns_reason_check check (reason in ('DAMAGED_AFTER_RECEIPT', 'WRONG_ITEM', 'EXPIRED'))");
        DB::statement("alter table inv_supplier_returns add constraint inv_supplier_returns_status_check check (status in ('POSTED'))");

        Schema::create('inv_supplier_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->foreignUuid('return_id')->constrained('inv_supplier_returns');
            $table->foreignUuid('receipt_line_id')->constrained('inv_goods_receipt_lines');
            $table->unsignedInteger('line_no');
            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');
            $table->decimal('quantity', 18, 4);
            $table->string('uom');
            $table->foreignUuid('movement_id')->constrained('inv_stock_movements');
            $table->string('credit_note_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'return_id', 'line_no']);
            $table->index(['tenant_id', 'receipt_line_id']);
        });

        DB::statement('alter table inv_supplier_return_lines add constraint inv_supplier_return_lines_quantity_positive check (quantity > 0)');
        Rls::enable('inv_supplier_returns');
        Rls::enableAppendOnly('inv_supplier_returns');
        Rls::enable('inv_supplier_return_lines');
        Rls::enableAppendOnly('inv_supplier_return_lines');
    }

    public function down(): void
    {
        Rls::disable('inv_supplier_return_lines');
        Rls::disable('inv_supplier_returns');
        Schema::dropIfExists('inv_supplier_return_lines');
        Schema::dropIfExists('inv_supplier_returns');
    }
};
