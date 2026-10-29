<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the Stock Adjustment workflow
 * (18-inventory-and-warehousing-domain.md §49, §94, §95, §224, §250; ADR-170 D-H).
 *
 * A justified discrepancy correction — the ONLY sanctioned direct write to a
 * stock quantity (§49). AggregateRoot, aggregate-0 shape. Lifecycle simplified
 * to §321's two named commands + a reject (ADR-170 D-H — NO DRAFT, NO separate
 * POSTED state):
 *
 *   PENDING_APPROVAL → APPROVED | REJECTED
 *
 * `ApproveStockAdjustment` writes the compensating `inv_stock_movements` row +
 * updates the balance via `StockLedgerService` in the same transaction — that
 * IS "posting". Self-approval is rejected (`approved_by_user_id !=
 * requested_by_user_id` — §224 / Inv 12, the T.1 interim segregation-of-duties
 * floor). `resulting_movement_id` is the §250 "Movement Reference".
 *
 * `reason_code` (§94) is open-coded normalised-uppercase. `reason_text` /
 * `rejection_reason` are free text, ROW-ONLY (the ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();   // = the location's facility

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->string('direction');            // INCREASE | DECREASE
            $table->decimal('quantity', 18, 4);     // POSITIVE
            $table->string('reason_code');          // §94 — open-coded normalised uppercase
            $table->text('reason_text')->nullable(); // ROW-ONLY (ADR-136)

            $table->string('status')->default('PENDING_APPROVAL'); // PENDING_APPROVAL | APPROVED | REJECTED

            $table->uuid('requested_by_user_id');
            $table->timestampTz('requested_at');
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable(); // ROW-ONLY (ADR-136)

            $table->foreignUuid('resulting_movement_id')->nullable()->constrained('inv_stock_movements'); // §250

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'item_id', 'location_id']);
        });

        DB::statement('alter table inv_stock_adjustments add constraint inv_stock_adjustments_qty_positive check (quantity > 0)');
        DB::statement("alter table inv_stock_adjustments add constraint inv_stock_adjustments_direction check (direction in ('INCREASE', 'DECREASE'))");

        Rls::enable('inv_stock_adjustments');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_adjustments');
        Schema::dropIfExists('inv_stock_adjustments');
    }
};
