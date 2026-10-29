<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.4) — the Invoice, a facility financial document that
 * GROUPS POSTED charges into a request for payment
 * (20-billing-and-payments-domain.md §34, §35, §92, §287, §296; ADR-190 D-A).
 *
 * ADR-190 INV-1: an Invoice posts ZERO `bill_account_ledger_entries` rows — the
 * ledger (charges + Bill.5 payments) is the sole `FinancialAccount` balance
 * source; the Invoice moves no money and holds no obligation. Mutable working
 * record (`Rls::enable`) — `DRAFT → ISSUED → VOIDED`. `invoice_number` is minted
 * ONLY at IssueInvoice (INV-6). `net` is FROZEN at ISSUE (INV-4). SELF_PAY only
 * (INV-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');

            $table->string('invoice_number')->nullable();       // INV-%08d — minted at IssueInvoice
            $table->string('payer_type')->default('SELF_PAY');  // the only supported value (INV-5)
            $table->string('status')->default('DRAFT');         // DRAFT | ISSUED | VOIDED

            $table->decimal('gross', 18, 4)->default(0);        // Σ(active line amounts)
            $table->decimal('discount', 18, 4)->default(0);     // '0.00' in Bill.4 (§29–§31)
            $table->decimal('tax', 18, 4)->default(0);          // '0.00' in Bill.4 (§32)
            $table->decimal('net', 18, 4)->default(0);          // gross − discount + tax; FROZEN at ISSUE
            $table->string('currency')->default('TZS');

            $table->timestampTz('issued_at')->nullable();
            $table->uuid('issued_by_user_id')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_user_id')->nullable();
            $table->string('void_reason_code')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'invoice_number']);    // INV-%08d, tenant-wide unique (ADR-115); null repeats allowed
            $table->index(['tenant_id', 'financial_account_id', 'status']);
        });

        Rls::enable('bill_invoices');
    }

    public function down(): void
    {
        Rls::disable('bill_invoices');
        Schema::dropIfExists('bill_invoices');
    }
};
