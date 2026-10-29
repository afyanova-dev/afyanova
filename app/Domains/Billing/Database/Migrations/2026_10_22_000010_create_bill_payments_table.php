<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.5b) — a Payment: money actually received against a
 * Financial Account (20-billing-and-payments-domain.md §57 / §288; ADR-191
 * B5-B / B5-C / G-6 / G-7).
 *
 * A Payment posts ONE `PAYMENT` / `CREDIT` ledger entry for its FULL amount at
 * `RecordPayment` (B5-B) — `outstanding` may go negative (= patient credit).
 * `RECORDED → REVERSED` (B5-C — no gateway lifecycle); a reversal is a status
 * transition on this row + a compensating `PAYMENT` / `DEBIT` ledger entry
 * (NOT a separate append-only sub-record). Mutable working record — `Rls::enable`.
 *
 * `facility_id` = the OWNING ACCOUNT's facility (financial truth — G-7);
 * `collected_at_facility_id` / `collected_by_user_id` = the collection point
 * (§150 / §151). `method` excludes the payer-remittance tenders (G-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                        // the owning account's facility (G-7)

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');

            $table->string('method');                           // CASH | CARD | BANK_TRANSFER | MOBILE_MONEY | POS | OTHER (G-6)
            $table->decimal('amount', 18, 4);                   // > 0
            $table->string('currency')->default('TZS');
            $table->string('status')->default('RECORDED');      // RECORDED | REVERSED

            $table->string('payment_reference')->nullable();    // external txn ref where applicable (§63)
            $table->uuid('collected_at_facility_id');           // the cash desk / collection facility (§151)
            $table->uuid('collected_by_user_id');               // the cashier (§152)
            $table->timestampTz('received_at');                 // when the money was received

            $table->uuid('reversal_id')->nullable();            // the compensating ledger DEBIT's source_id
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            $table->string('reverse_reason_code')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'financial_account_id', 'status']);
            $table->index(['tenant_id', 'collected_at_facility_id', 'recorded_at']); // Bill.6 cash-session roll-up
        });

        Rls::enable('bill_payments');
    }

    public function down(): void
    {
        Rls::disable('bill_payments');
        Schema::dropIfExists('bill_payments');
    }
};
