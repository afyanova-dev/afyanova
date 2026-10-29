<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.5c) — a payment allocation: how much of a Payment
 * settled which ISSUED Invoice (20-billing-and-payments-domain.md §65 / §294;
 * ADR-191 B5-A).
 *
 * The SUB-LEDGER, NOT the ledger — an allocation posts NO
 * `bill_account_ledger_entries` row (the Payment already posted its full-amount
 * `PAYMENT` / `CREDIT`). Mutable (`allocation_active` toggled — the Bill.4
 * INV-3 pattern), NOT DB-append-only. ONE live allocation per (payment,
 * invoice) — the partial unique index. Over-allocation past `payment.amount` or
 * `invoice.net` is guarded in the handler under a row lock on BOTH parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->uuid('financial_account_id');               // denorm — the account-scoped reads

            $table->decimal('amount', 18, 4);                   // > 0
            $table->boolean('allocation_active')->default(true);

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        // ADR-191 B5-A — one live allocation per (payment, invoice).
        DB::statement(
            'create unique index bill_payment_allocations_one_live_per_pair
                 on bill_payment_allocations (tenant_id, payment_id, invoice_id)
              where allocation_active = true'
        );

        Rls::enable('bill_payment_allocations');
    }

    public function down(): void
    {
        Rls::disable('bill_payment_allocations');
        Schema::dropIfExists('bill_payment_allocations');
    }
};
