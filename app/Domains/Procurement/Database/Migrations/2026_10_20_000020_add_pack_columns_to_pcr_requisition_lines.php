<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pack (buying unit) conversion for purchase-requisition lines — mirrors the
 * equivalent migration for `pcr_po_lines` (2026_10_20_000010).
 *
 * A requester asks for "5 BOX of 100 capsules", not "500 capsules".
 * `quantity` is the ENTERED quantity in `unit` (e.g. 5 BOX).
 * `ordered_base_qty` is generated as `quantity * coalesce(pack_size, 1)` and
 * is the base-unit truth used when the requisition is converted to an order.
 *
 * Existing rows are backfilled with `unit = uom` and `pack_size = NULL` so
 * their meaning is unchanged: 1 base unit = 1 base unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_requisition_lines', function (Blueprint $table): void {
            $table->string('unit', 20)->nullable();          // entered unit (e.g. BOX)
            $table->decimal('pack_size', 18, 4)->nullable(); // base units per entered unit
        });

        // Every line written before packs existed was in its own base unit.
        DB::statement('update pcr_requisition_lines set unit = uom');
        DB::statement('alter table pcr_requisition_lines alter column unit set not null');

        // One source of truth for base quantities.
        DB::statement(
            'alter table pcr_requisition_lines '.
            'add column ordered_base_qty numeric(24, 4) '.
            'generated always as (quantity * coalesce(pack_size, 1)) stored'
        );

        DB::statement(
            'alter table pcr_requisition_lines '.
            'add constraint pcr_requisition_lines_pack_size_positive '.
            'check (pack_size is null or pack_size > 0)'
        );

        DB::statement(
            'alter table pcr_requisition_lines '.
            'add constraint pcr_requisition_lines_pack_unit_differs '.
            'check (pack_size is null or unit <> uom)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table pcr_requisition_lines drop constraint if exists pcr_requisition_lines_pack_unit_differs');
        DB::statement('alter table pcr_requisition_lines drop constraint if exists pcr_requisition_lines_pack_size_positive');
        DB::statement('alter table pcr_requisition_lines drop column if exists ordered_base_qty');

        Schema::table('pcr_requisition_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit', 'pack_size']);
        });
    }
};
