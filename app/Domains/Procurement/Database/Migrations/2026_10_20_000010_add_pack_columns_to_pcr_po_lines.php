<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pack (buying unit) conversion, second slice — purchase-order lines
 * (frontend-foundation-inventory-operations.md Phase 8;
 * `inventory-item-master-spec.md` §7).
 *
 * A buyer orders in the unit the supplier sells: "3 BOX of 100", "2.5 PACK of
 * 20". `ordered_qty` is that ENTERED quantity and `unit_price` is the price of
 * ONE entered unit, so `line_total = ordered_qty * unit_price` stays exact — no
 * price division and no rounding.
 *
 * `uom` remains the item's BASE unit (the unit stock is counted, received and
 * expired in). `ordered_base_qty` is GENERATED as
 * `ordered_qty * coalesce(pack_size, 1)`: the one place base quantities come
 * from, so receiving reconciliation and contract ceilings can never drift from
 * the entered numbers.
 *
 * Existing rows are backfilled with `unit = uom` and `pack_size = NULL`, which
 * makes `ordered_base_qty = ordered_qty` — their meaning is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_po_lines', function (Blueprint $table): void {
            $table->string('unit', 20)->nullable();          // the unit the buyer entered (e.g. BOX)
            $table->decimal('pack_size', 18, 4)->nullable();  // base units per `unit` on this line
        });

        // Every line written before packs existed was in its own base unit.
        DB::statement('update pcr_po_lines set unit = uom');
        DB::statement('alter table pcr_po_lines alter column unit set not null');

        // One source of truth for base quantities: ordered_qty x pack size.
        DB::statement('alter table pcr_po_lines add column ordered_base_qty numeric(24, 4) generated always as (ordered_qty * coalesce(pack_size, 1)) stored');

        DB::statement('alter table pcr_po_lines add constraint pcr_po_lines_pack_size_positive check (pack_size is null or pack_size > 0)');
        DB::statement('alter table pcr_po_lines add constraint pcr_po_lines_pack_unit_differs check (pack_size is null or unit <> uom)');
    }

    public function down(): void
    {
        DB::statement('alter table pcr_po_lines drop constraint if exists pcr_po_lines_pack_unit_differs');
        DB::statement('alter table pcr_po_lines drop constraint if exists pcr_po_lines_pack_size_positive');
        DB::statement('alter table pcr_po_lines drop column if exists ordered_base_qty');

        Schema::table('pcr_po_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit', 'pack_size']);
        });
    }
};
