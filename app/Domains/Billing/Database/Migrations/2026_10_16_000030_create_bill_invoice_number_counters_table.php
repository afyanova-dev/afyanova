<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.4) — tenant-scoped invoice-number counter
 * (ADR-115; ADR-190 D-C). `INV-%08d`. Allocated by
 * App\Domains\Billing\Infrastructure\Sequence\InvoiceNumberSequence inside the
 * `IssueInvoice` command transaction — the
 * `bill_financial_account_number_counters` shape verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_invoice_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('bill_invoice_number_counters');
    }

    public function down(): void
    {
        Rls::disable('bill_invoice_number_counters');
        Schema::dropIfExists('bill_invoice_number_counters');
    }
};
