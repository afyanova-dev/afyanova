<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pack (buying unit) conversion, first slice — the item's default pack plus the
 * pack a receipt actually used (frontend-foundation-inventory-operations.md
 * Phase 8; `inventory-item-master-spec.md` §7's "pack → unit factor").
 *
 * Medicines are BOUGHT in one unit and COUNTED in another: a box of 100
 * tablets, a pack of 20 bottles. Stock, expiry and the ledger stay in the
 * item's base unit (`inv_items.uom`) — the pack is only how stock is typed in.
 *
 * `inv_items.pack_unit` / `pack_size` are the item's DEFAULT buying unit (e.g.
 * BOX = 100). Both null means the item has no pack; both set means it does —
 * they are a pair, enforced below.
 *
 * `inv_stock_movements.entered_unit` / `entered_quantity` / `pack_size` record
 * what the receiver actually typed on THAT receipt (e.g. "3 BOX, 100 per box"),
 * because suppliers differ: ORS comes in boxes of 10 from one and 50 from
 * another, and a dispensary may buy 8 bottles out of a pack of 20. `quantity`
 * stays the base-unit truth; these three are the honest note of how it arrived,
 * so changing the item's default later cannot rewrite old receipts. All three
 * are nullable — every movement recorded before this migration, and every plain
 * base-unit entry, carries none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_items', function (Blueprint $table): void {
            $table->string('pack_unit', 20)->nullable();      // e.g. BOX
            $table->decimal('pack_size', 18, 4)->nullable();   // base units per pack, e.g. 100
        });

        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->string('entered_unit', 20)->nullable();
            $table->decimal('entered_quantity', 18, 4)->nullable();
            $table->decimal('pack_size', 18, 4)->nullable();
        });

        // The item's pack is a pair, and a pack of one base unit is not a pack.
        DB::statement('alter table inv_items add constraint inv_items_pack_pair check ((pack_unit is null) = (pack_size is null))');
        DB::statement('alter table inv_items add constraint inv_items_pack_size check (pack_size is null or pack_size > 1)');

        // A receipt's entered record is a pair too, and the size is a real size.
        DB::statement('alter table inv_stock_movements add constraint inv_stock_movements_entered_pair check (entered_unit is not null or (entered_quantity is null and pack_size is null))');
        DB::statement('alter table inv_stock_movements add constraint inv_stock_movements_entered_positive check (entered_quantity is null or entered_quantity > 0)');
        DB::statement('alter table inv_stock_movements add constraint inv_stock_movements_entered_pack_positive check (pack_size is null or pack_size > 0)');
    }

    public function down(): void
    {
        DB::statement('alter table inv_stock_movements drop constraint if exists inv_stock_movements_entered_pack_positive');
        DB::statement('alter table inv_stock_movements drop constraint if exists inv_stock_movements_entered_positive');
        DB::statement('alter table inv_stock_movements drop constraint if exists inv_stock_movements_entered_pair');
        DB::statement('alter table inv_items drop constraint if exists inv_items_pack_size');
        DB::statement('alter table inv_items drop constraint if exists inv_items_pack_pair');

        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['entered_unit', 'entered_quantity', 'pack_size']);
        });

        Schema::table('inv_items', function (Blueprint $table): void {
            $table->dropColumn(['pack_unit', 'pack_size']);
        });
    }
};
