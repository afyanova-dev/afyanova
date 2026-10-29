<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.3b) — the Charge, one per `pharmacy.MedicationDispensed`
 * event (20-billing-and-payments-domain.md §14, §286, §299; ADR-189 D-C).
 *
 * Mutable — the `status` transition only (POSTED → REVERSED). The `amount`
 * columns are immutable: `amount = Money::multiply(unit_price, quantity)`
 * HALF-UP to scale-2 (ADR-189 INV-3), recompute-auditable from `unit_price` +
 * `quantity` + the frozen `tariff_row_id` (§194 / §195 — no rounding log).
 * `billing_service_code` is snapshot at charge time (C6 — a later service
 * rename must not retroactively change a printed line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');

            $table->uuid('billing_service_id');
            $table->string('billing_service_code');            // C6 — snapshot
            $table->uuid('billable_event_id');
            $table->uuid('source_ref');                        // the dispensing_id (§129)

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);             // the Bill.2 tariff snapshot (§235)
            $table->uuid('tariff_row_id');
            $table->string('resolved_scope');                 // FACILITY | TENANT_DEFAULT
            $table->decimal('amount', 18, 4);                 // Money::multiply(unit_price, quantity), HALF-UP
            $table->string('currency')->default('TZS');

            $table->string('status')->default('POSTED');      // POSTED | REVERSED
            $table->uuid('reversal_id')->nullable();          // the bill_charge_reversals row

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'billable_event_id']); // one charge per billable event (INV-2)
            $table->index(['tenant_id', 'financial_account_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('bill_charges');
    }

    public function down(): void
    {
        Rls::disable('bill_charges');
        Schema::dropIfExists('bill_charges');
    }
};
