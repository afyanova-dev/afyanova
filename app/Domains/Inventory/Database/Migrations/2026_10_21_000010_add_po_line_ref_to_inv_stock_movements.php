<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-01 — make the receiving line authoritative.
 *
 * `inv_stock_movements.po_line_ref` carries the exact `pcr_po_lines.id` chosen
 * on the receiving surface. It is an OPAQUE cross-domain identifier (no FK),
 * exactly like `po_ref`, and is nullable so historical movements and
 * non-PO receipts remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->uuid('po_line_ref')->nullable()->after('po_ref');
            $table->index(['tenant_id', 'po_line_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'po_line_ref']);
            $table->dropColumn('po_line_ref');
        });
    }
};
