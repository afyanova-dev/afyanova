<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.1) — tenant-scoped financial-account-number counter
 * (ADR-115; ADR-187 D-B). `FA-%08d`.
 *
 * One row per tenant (`UNIQUE (tenant_id)`). Allocated by
 * App\Domains\Billing\Infrastructure\Sequence\FinancialAccountNumberSequence
 * inside the `OpenFinancialAccount` command transaction — the
 * `rx_pharmacy_order_number_counters` / `enc_encounter_number_counters` shape
 * verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_financial_account_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('bill_financial_account_number_counters');
    }

    public function down(): void
    {
        Rls::disable('bill_financial_account_number_counters');
        Schema::dropIfExists('bill_financial_account_number_counters');
    }
};
