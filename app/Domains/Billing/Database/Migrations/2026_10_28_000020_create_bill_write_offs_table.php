<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.7a) — a write-off: an outstanding amount recorded as
 * uncollectable under the applicable policy (20-billing-and-payments-domain.md
 * §78 / §82 / §139; ADR-191 G-3 / D7-A).
 *
 * `REQUESTED → APPLIED → REVERSED` (+ `REJECTED`). The ledger `WRITE_OFF` /
 * `CREDIT` posts ONLY on `APPLIED`; a reversal posts a compensating `WRITE_OFF`
 * / `DEBIT`. The amount / reason are IMMUTABLE — only `status` + the approval /
 * rejection / reversal metadata change. Mutable working record — `Rls::enable`.
 * NEVER touches `bill_charges` / `bill_invoices` / `bill_invoice_lines` (§134);
 * `invoice_id` is a reporting tag only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_write_offs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                        // the account's facility

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');
            $table->uuid('invoice_id')->nullable();             // reporting tag only (§134 — never mutated)

            $table->decimal('amount', 18, 4);                   // > 0
            $table->string('currency')->default('TZS');
            $table->string('reason_code');                      // WriteOffReason (§78)
            $table->string('status')->default('REQUESTED');     // REQUESTED | APPLIED | REVERSED | REJECTED
            $table->string('note')->nullable();
            $table->boolean('dual_control')->default(true);     // was a distinct approver required? (informational)

            $table->timestampTz('requested_at');
            $table->uuid('requested_by_user_id');
            $table->timestampTz('approved_at')->nullable();     // = APPLIED time; the ledger post time
            $table->uuid('approved_by_user_id')->nullable();    // = requested_by when self-applied below the threshold
            $table->timestampTz('rejected_at')->nullable();
            $table->uuid('rejected_by_user_id')->nullable();
            $table->string('reject_reason_code')->nullable();
            $table->uuid('reversal_id')->nullable();            // the compensating ledger DEBIT's source_id
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            $table->string('reverse_reason_code')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'financial_account_id', 'status']);
            $table->index(['tenant_id', 'status']);             // the approver worklist
        });

        Rls::enable('bill_write_offs');
    }

    public function down(): void
    {
        Rls::disable('bill_write_offs');
        Schema::dropIfExists('bill_write_offs');
    }
};
