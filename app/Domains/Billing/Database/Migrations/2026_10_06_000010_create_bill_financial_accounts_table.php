<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.1) — the Financial Account, the root of the Billing &
 * Payments domain (20-billing-and-payments-domain.md §7, §8, §9, §144–§149,
 * §300, §301; ADR-187 D-B).
 *
 * ONE account per patient per facility (HD-1 — Tanzanian facilities keep
 * financially independent books; a consolidated organization account is
 * deferred config, §148). Mutable working record — `Rls::enable` only, NOT
 * append-only (the lifecycle mutates `status`). `status` is an open-coded string
 * constant (the `PharmacyOrderStatus` / `StockAdjustment` precedent). Single
 * currency `TZS` (§190 / §191 — multi-currency deferred).
 *
 * The append-only `bill_account_ledger_entries` table (Bill.1, next migration)
 * is the definition of the balance — §84 "balance must be explainable from
 * transactions", §88 posted-transaction immutability. No charge / invoice /
 * payment writer exists yet (Bill.2+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_financial_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                        // the account's scope (HD-1)

            $table->uuid('patient_id');                         // plain uuid, NO FK — verified via PatientApi at open
            $table->string('account_number');                   // FA-%08d, tenant-wide unique (ADR-115)

            $table->string('status')->default('OPEN');          // OPEN | ACTIVE | SETTLED | CLOSED (§300)
            $table->string('currency')->default('TZS');         // single-currency (§190)

            $table->timestampTz('opened_at');
            $table->uuid('opened_by_user_id');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->string('close_reason_code')->nullable();    // FinancialAccountReason::CLOSE (§107)

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'account_number']);    // ADR-115
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        // ADR-187 D-B — at most one non-closed account per patient per facility.
        DB::statement(
            "create unique index bill_financial_accounts_one_active_per_scope
                 on bill_financial_accounts (tenant_id, patient_id, facility_id)
              where status <> 'CLOSED'"
        );

        Rls::enable('bill_financial_accounts');
    }

    public function down(): void
    {
        Rls::disable('bill_financial_accounts');
        Schema::dropIfExists('bill_financial_accounts');
    }
};
