<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the materialised Stock Balance
 * (18-inventory-and-warehousing-domain.md §29–§31, §85, §86, §246, §252, §254,
 * §408.4; ADR-170 D-F).
 *
 * The materialised current-state aggregate: `balance = Σ movements`,
 * reconcilable against `inv_stock_movements` (§254 — "the authoritative
 * historical trail remains the movement ledger"). The event source for the
 * module — `StockBalance::applyMovement()` mutates the on-hand quantity and
 * records `StockReceived` / `StockConsumed` / `StockAdjusted`.
 *
 * Concurrency: aggregate-0 optimistic `version` AND `SELECT … FOR UPDATE`
 * (`StockLedgerService` / the repository) — §302 / §304 / §307.
 *
 * IDENTITY: a raw-SQL unique index with NULLS NOT DISTINCT so non-batch items
 * (`batch_id` NULL) collapse to ONE row per (item, location) — the CC.2
 * `care_episodes` precedent (the migration builder cannot express it). Without
 * it, Postgres treats NULLs as distinct and the on-hand total fragments,
 * defeating the Inv 5 negative-stock guard.
 *
 * `on_hand_qty` (§31) is the physical stock at the location. `reserved_qty`
 * (§30 / §32) is ALWAYS 0 in Inv.1 — it is the single pre-reserved closed-code
 * extension hook this frame ADR names (written by Inv.2's `ReserveStock`).
 * `available = on_hand_qty − reserved_qty` is COMPUTED in the reader/DTO, NOT
 * stored. The other §29 buckets (quarantined / expired / damaged / in_transit)
 * are added by their own increments (Inv.4 / Inv.6). NO per-row `status`
 * column (§38's status is carried by those deferred bucket columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();   // = the location's facility

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->decimal('on_hand_qty', 18, 4)->default(0);   // §31
            $table->decimal('reserved_qty', 18, 4)->default(0);  // §30 / §32 — Inv.2 hook, 0 in Inv.1

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'facility_id']);
            $table->index(['tenant_id', 'location_id']);
        });

        // NULLS NOT DISTINCT (PG 15+) — one balance row per (item, location,
        // batch), and per (item, location) for non-batch items (ADR-170 D-F /
        // blocker B1; the CC.2 `care_episodes` precedent).
        DB::statement(
            'create unique index inv_stock_balances_identity '.
            'on inv_stock_balances (tenant_id, item_id, location_id, batch_id) nulls not distinct'
        );

        DB::statement('alter table inv_stock_balances add constraint inv_stock_balances_on_hand_nonneg check (on_hand_qty >= 0)');
        DB::statement('alter table inv_stock_balances add constraint inv_stock_balances_reserved_nonneg check (reserved_qty >= 0)');

        Rls::enable('inv_stock_balances');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_balances');
        Schema::dropIfExists('inv_stock_balances');
    }
};
