<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.3b) — the Dispensing Reversal record
 * (16-pharmacy-domain.md §59, §60, §128; ADR-185 D-B).
 *
 * The error-correction inverse of a `rx_dispensings` row. WRITE-ONCE — a reversal
 * is never itself reversed or edited — so `Rls::enable` (tenant FORCE) **and**
 * `Rls::enableAppendOnly` (RESTRICTIVE no UPDATE / DELETE), per the
 * `governance-rulings` interpretation.
 *
 * §128 — "a reversal preserves the original dispensing event": the original
 * `rx_dispensings` row is NEVER touched; item / location / quantity / batch are
 * COPIED here. `reversal_movement_id` links the compensating
 * `inv_stock_movements` IN (`movement_type = CONSUMPTION_REVERSAL`). ONE reversal
 * per Dispensing — the partial unique index (the `rx_pharmacy_orders`
 * one-open-per-prescription precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_dispensing_reversals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                       // = the dispensing's facility

            $table->uuid('dispensing_id');                     // the reversed rx_dispensings row — plain uuid, NO FK
            $table->uuid('pharmacy_order_id');                 // denormalised
            $table->uuid('prescription_id');                   // denormalised
            $table->uuid('patient_id');                        // denormalised

            $table->string('reversal_number');                 // DRV-%08d, tenant-wide unique (ADR-115)

            $table->uuid('item_id');                            // copied from the original Dispensing
            $table->uuid('location_id');
            $table->string('batch_number')->nullable();
            $table->decimal('quantity', 18, 4);               // the amount put back (== the dispensing's quantity — FULL reversal)

            $table->uuid('reversal_movement_id');               // the compensating inv_stock_movements IN (ADR-185 D-C)

            $table->string('reason_code');                     // open-coded, SET-validated (ADR-185 D-B)
            $table->text('reason_note')->nullable();           // ROW-ONLY (ADR-136)

            $table->uuid('reversed_by_user_id');
            $table->string('status')->default('COMPLETED');   // COMPLETED (single value)

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only — write-once (governance-rulings)
            $table->timestampsTz();

            $table->unique(['tenant_id', 'reversal_number']);  // ADR-115
            $table->index(['tenant_id', 'pharmacy_order_id']); // the net-cumulative read
            $table->index(['tenant_id', 'prescription_id']);
        });

        // ADR-185 D-B — one reversal per Dispensing.
        DB::statement(
            'create unique index rx_dispensing_reversals_one_per_dispensing '.
            'on rx_dispensing_reversals (tenant_id, dispensing_id)'
        );

        Rls::enable('rx_dispensing_reversals');
        Rls::enableAppendOnly('rx_dispensing_reversals');
    }

    public function down(): void
    {
        Rls::disable('rx_dispensing_reversals');
        Schema::dropIfExists('rx_dispensing_reversals');
    }
};
