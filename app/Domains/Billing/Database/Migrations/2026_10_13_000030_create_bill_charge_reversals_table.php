<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.3b) — the charge reversal
 * (20-billing-and-payments-domain.md §113, §135; ADR-189 D-D, sponsor ruling C8).
 *
 * The error-correction inverse of a POSTED Charge, from a
 * `pharmacy.MedicationDispensingReversed` event. APPEND-ONLY — `Rls::enable` +
 * `Rls::enableAppendOnly` (the `rx_dispensing_reversals` precedent). ONE per
 * Charge (INV-2). FULL reversal only (Pharm.3b parity). The original
 * `bill_charges` row is never mutated except `status := REVERSED` +
 * `reversal_id` — the amount columns are untouched (§88).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_charge_reversals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->uuid('charge_id');
            $table->uuid('financial_account_id');
            $table->uuid('source_reversal_event_id');          // the MedicationDispensingReversed eventId
            $table->uuid('dispensing_id');

            $table->decimal('amount', 18, 4);                  // = the charge amount (FULL reversal only)
            $table->string('currency')->default('TZS');
            $table->string('reason_code');                     // from the event

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only — write-once
            $table->timestampsTz();

            $table->unique(['tenant_id', 'charge_id']);        // one reversal per charge (INV-2)
        });

        Rls::enable('bill_charge_reversals');
        Rls::enableAppendOnly('bill_charge_reversals');
    }

    public function down(): void
    {
        Rls::disable('bill_charge_reversals');
        Schema::dropIfExists('bill_charge_reversals');
    }
};
