<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.2) — the Stock Reservation aggregate
 * (18-inventory-and-warehousing-domain.md §32, §112–§116, §150, §217, §303,
 * §245; ADR-180 D-B).
 *
 * A reservation COMMITS stock to an intended consumer without physically moving
 * it (§115): it increments `inv_stock_balances.reserved_qty` only — NO
 * `inv_stock_movements` row (the ledger is on-hand deltas). Lifecycle
 * ACTIVE → {FULFILLED, RELEASED, EXPIRED, CANCELLED}; `status` open-coded string
 * constants on the model (the `StockAdjustment` precedent, NOT a PHP enum).
 * Mutable working record — `Rls::enable` only, NOT append-only.
 *
 * `consumer_ref` is the OPAQUE business-consumer id (§150 — "Theatre Case
 * SURG-00123"); NO cross-domain FK, NO validation (§282). `reason` /
 * `released_reason` free text is ROW-ONLY (the ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();   // = the location's facility

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->decimal('reserved_qty', 18, 4);            // Quantity — CHECK > 0
            $table->string('consumer_type');                   // open-coded normalised uppercase
            $table->string('consumer_ref')->nullable();        // OPAQUE business id (§150); NO FK
            $table->text('reason')->nullable();                // ROW-ONLY (ADR-136)
            $table->timestampTz('hold_until')->nullable();     // §114 — NULL = no auto-expiry

            $table->string('status')->default('ACTIVE');       // ACTIVE | FULFILLED | RELEASED | EXPIRED | CANCELLED
            $table->foreignUuid('fulfilled_by_movement_id')->nullable()->constrained('inv_stock_movements');
            $table->string('released_reason')->nullable();     // RELEASED | EXPIRED | CANCELLED | FULFILLED

            $table->uuid('requested_by_user_id');
            $table->uuid('released_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'location_id', 'status']);
            $table->index(['tenant_id', 'status', 'hold_until']);
        });

        DB::statement('alter table inv_stock_reservations add constraint inv_stock_reservations_qty_positive check (reserved_qty > 0)');

        Rls::enable('inv_stock_reservations');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_reservations');
        Schema::dropIfExists('inv_stock_reservations');
    }
};
