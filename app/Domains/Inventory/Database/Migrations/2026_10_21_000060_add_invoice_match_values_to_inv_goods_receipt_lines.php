<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_goods_receipt_lines', function (Blueprint $table): void {
            $table->string('po_unit')->nullable();
            $table->decimal('po_unit_price', 18, 2)->nullable();
            $table->decimal('invoice_quantity', 18, 4)->nullable();
            $table->decimal('invoice_unit_price', 18, 2)->nullable();
        });

        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_po_unit_price_nonneg check (po_unit_price is null or po_unit_price >= 0)');
        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_invoice_quantity_nonneg check (invoice_quantity is null or invoice_quantity >= 0)');
        DB::statement('alter table inv_goods_receipt_lines add constraint inv_goods_receipt_lines_invoice_unit_price_nonneg check (invoice_unit_price is null or invoice_unit_price >= 0)');
    }

    public function down(): void
    {
        DB::statement('alter table inv_goods_receipt_lines drop constraint if exists inv_goods_receipt_lines_po_unit_price_nonneg');
        DB::statement('alter table inv_goods_receipt_lines drop constraint if exists inv_goods_receipt_lines_invoice_quantity_nonneg');
        DB::statement('alter table inv_goods_receipt_lines drop constraint if exists inv_goods_receipt_lines_invoice_unit_price_nonneg');
        Schema::table('inv_goods_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn(['po_unit', 'po_unit_price', 'invoice_quantity', 'invoice_unit_price']);
        });
    }
};
