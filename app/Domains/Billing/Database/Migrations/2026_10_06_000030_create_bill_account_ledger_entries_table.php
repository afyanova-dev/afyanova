<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.1) — the Financial Account Ledger
 * (20-billing-and-payments-domain.md §83–§88, §290, §293, §295; ADR-187 D-C).
 *
 * The single definition of a patient's balance:
 *   outstanding = Σ(amount WHERE direction = 'DEBIT') − Σ(amount WHERE direction = 'CREDIT')
 * — §84 "balance must be explainable from transactions", never a stored column.
 *
 * APPEND-ONLY (§88 posted-transaction immutability): `Rls::enable` (tenant
 * FORCE) **and** `Rls::enableAppendOnly` (RESTRICTIVE no UPDATE / DELETE) — the
 * `rx_dispensings` precedent + the `governance-rulings` interpretation.
 *
 * NOT an aggregate — a ledger-row structure (§290). Written ONLY by Billing's
 * own repository inside the command transaction that creates the originating
 * fact. Bill.1 ships the table + the reader; **there is no writer yet** — the
 * first `entry_type = 'CHARGE'` row is posted by Bill.3's `MedicationDispensed`
 * consumer (§225). `version` is shape/conformance-only (write-once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_account_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                        // denorm — the per-facility reconciliation filter

            $table->uuid('financial_account_id');               // the owning bill_financial_accounts row

            $table->string('entry_type');                       // CHARGE | PAYMENT | CREDIT | DEBIT | WRITE_OFF | REFUND | ADJUSTMENT
            $table->string('direction');                        // DEBIT (raises balance) | CREDIT (lowers balance)
            $table->decimal('amount', 18, 4);                   // >= 0 (Money scale-2 today; scale-4 headroom)
            $table->string('currency')->default('TZS');

            $table->string('source_type');                      // the originating row's kind — coded, PHI-free
            $table->uuid('source_id');                          // the originating charge / payment / … row
            $table->string('memo_code')->nullable();            // coded; ROW-ONLY free text is not modelled in Bill.1

            $table->timestampTz('posted_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);  // shape/conformance-only — write-once
            $table->timestampsTz();

            $table->index(['tenant_id', 'financial_account_id', 'posted_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Rls::enable('bill_account_ledger_entries');
        Rls::enableAppendOnly('bill_account_ledger_entries');
    }

    public function down(): void
    {
        Rls::disable('bill_account_ledger_entries');
        Schema::dropIfExists('bill_account_ledger_entries');
    }
};
