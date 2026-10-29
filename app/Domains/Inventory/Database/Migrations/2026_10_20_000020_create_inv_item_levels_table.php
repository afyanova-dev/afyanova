<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (low-stock alerts) — the minimum level at which an item should be
 * reordered, per facility.
 *
 * The item master is shared by the whole tenant, but "running low" is not: a
 * dispensary and a hospital buy the same item at completely different scales
 * (the same reasoning that made approval limits per facility). ONE row per
 * (facility, item). NO row means "not tracked" — never "minimum zero" — so an
 * untouched catalogue produces no false alarms. Clearing a level deletes the
 * row; the audit entry keeps the history.
 *
 * `min_level` is in the item's BASE unit (CAPSULE, not BOX) — the same unit
 * stock is counted in. The pack size is an ordering/display help, never a
 * second truth.
 *
 * Mutable config row — `Rls::enable` only, NOT append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_item_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->foreignUuid('item_id')->constrained('inv_items');

            $table->decimal('min_level', 18, 4);   // Quantity in the base uom — CHECK > 0

            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'item_id']);
            $table->index(['tenant_id', 'facility_id']);
        });

        DB::statement('alter table inv_item_levels add constraint inv_item_levels_min_level_positive check (min_level > 0)');

        Rls::enable('inv_item_levels');
    }

    public function down(): void
    {
        Rls::disable('inv_item_levels');
        Schema::dropIfExists('inv_item_levels');
    }
};
