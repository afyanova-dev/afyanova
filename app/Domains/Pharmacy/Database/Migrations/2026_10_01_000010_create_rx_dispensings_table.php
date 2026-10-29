<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.3) — the Dispensing record (16-pharmacy-domain.md §24,
 * §44–§47, §127, §255; ADR-184 D-B).
 *
 * One row per dispensing EVENT; N per Pharmacy Order (§23 — partial dispensing,
 * repeat pickup). WRITE-ONCE — a reversal is a future compensating row, never an
 * UPDATE — so `Rls::enable` (tenant FORCE) **and** `Rls::enableAppendOnly`
 * (RESTRICTIVE no UPDATE / DELETE), per the `governance-rulings` interpretation
 * ("append-only tenant tables need BOTH").
 *
 * `authorized_quantity_value` / `_unit` are the FROZEN §112 ceiling snapshot
 * (ADR-183 C.10 `quantity_value`), captured on the FIRST dispensing of the order
 * from `ClinicalApi::getPrescribingDecision` and COPIED onto every subsequent
 * row (ADR-184 D-D). NULL when the prescribing decision carried no structured
 * quantity — then `dispensed_without_ceiling` is true and §112 is NOT enforced
 * for that order (ADR-183 D-M / ADR-184 HD-3).
 *
 * `reservation_id` / `movement_id` link to the `inv_stock_reservations` /
 * `inv_stock_movements` rows the same transaction created (ADR-184 D-G / D-L —
 * reserve+consume EXACTLY this event's quantity). `financial_clearance_ref` is
 * retained for historical compatibility; payment is checked at pickup from
 * Billing's invoice state. `status` is an open-coded string constant (the
 * `StockAdjustment` precedent) — always 'COMPLETED' in Pharm.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_dispensings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                       // = the order's facility (§81 cross-facility deferred)

            $table->uuid('pharmacy_order_id');                 // the Pharm.2a order — plain uuid, NO FK
            $table->uuid('prescription_id');                   // denormalised — the "what was dispensed for this Rx" read
            $table->uuid('patient_id');                        // denormalised

            $table->string('dispensing_number');               // DSP-%08d, tenant-wide unique (ADR-115)

            $table->uuid('item_id');                            // the pharmacist-selected inv_items.id (B1) — plain uuid, NO FK
            $table->uuid('location_id');                        // the inv_stock_locations id dispensed from
            $table->string('batch_number')->nullable();        // required iff the item is batch-tracked (Inventory enforces)
            $table->decimal('quantity', 18, 4);                // the amount dispensed THIS event
            $table->string('quantity_unit')->nullable();       // copied from the C.10 authorised unit (recorded as-is; ADR-184 D-E)

            $table->decimal('authorized_quantity_value', 18, 4)->nullable(); // FROZEN §112 ceiling (ADR-183 C.10) — copied to every row
            $table->string('authorized_quantity_unit')->nullable();
            $table->boolean('dispensed_without_ceiling')->default(false);     // true ⇒ null quantity_value, §112 NOT enforced (ADR-184 D-E)

            $table->uuid('reservation_id');                     // the inv_stock_reservations row (ADR-184 D-G)
            $table->uuid('movement_id');                        // the inv_stock_movements OUT row
            $table->string('financial_clearance_ref')->nullable(); // Legacy field; pickup checks Billing settlement

            $table->uuid('dispensed_by_user_id');
            $table->string('status')->default('COMPLETED');    // COMPLETED (single value; REVERSED is Pharm.3b)

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only — write-once (governance-rulings)
            $table->timestampsTz();

            $table->unique(['tenant_id', 'dispensing_number']); // ADR-115
            $table->index(['tenant_id', 'pharmacy_order_id']);  // the cumulative + first-event reads
            $table->index(['tenant_id', 'prescription_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('rx_dispensings');
        Rls::enableAppendOnly('rx_dispensings');
    }

    public function down(): void
    {
        Rls::disable('rx_dispensings');
        Schema::dropIfExists('rx_dispensings');
    }
};
