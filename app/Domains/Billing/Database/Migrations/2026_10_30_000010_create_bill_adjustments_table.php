<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.7b) — a post-charge account-level financial
 * adjustment (20-billing-and-payments-domain.md §81 / §82 / §137 / §138;
 * ADR-191 G-3 / D7-D).
 *
 * Reuses the Bill.7a SoD primitive verbatim (`FinancialControlStatus` /
 * `FinancialControlLifecycle` / `SelfApprovalNotPermitted` /
 * `bill_financial_control_settings` with `dualControlThreshold('ADJUSTMENT')`).
 * `REQUESTED → APPLIED → REVERSED` (+ `REJECTED`). The ledger `ADJUSTMENT` row
 * (direction per `AdjustmentType`) posts ONLY on `APPLIED`; a reversal posts
 * the OPPOSITE direction. The amount / type are IMMUTABLE. NEVER touches
 * `bill_charges` / `bill_invoices` / `bill_invoice_lines`; `invoice_id` is a
 * reporting tag. Point-of-charge discount / override / no-charge (§168 / §169)
 * are OUT of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');
            $table->uuid('invoice_id')->nullable();             // reporting tag only (§134)

            $table->decimal('amount', 18, 4);                   // > 0
            $table->string('currency')->default('TZS');
            $table->string('adjustment_type');                  // AdjustmentType (§81)
            $table->string('direction');                        // CREDIT | DEBIT — per the type
            $table->string('status')->default('REQUESTED');     // REQUESTED | APPLIED | REVERSED | REJECTED
            $table->string('note')->nullable();
            $table->boolean('dual_control')->default(true);

            $table->timestampTz('requested_at');
            $table->uuid('requested_by_user_id');
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->uuid('rejected_by_user_id')->nullable();
            $table->string('reject_reason_code')->nullable();
            $table->uuid('reversal_id')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            $table->string('reverse_reason_code')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'financial_account_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        Rls::enable('bill_adjustments');
    }

    public function down(): void
    {
        Rls::disable('bill_adjustments');
        Schema::dropIfExists('bill_adjustments');
    }
};
