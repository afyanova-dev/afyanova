<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.5b) — a Receipt: the payment-received confirmation
 * document, ONE per Payment (20-billing-and-payments-domain.md §90–§94;
 * ADR-191 B5-C).
 *
 * `RCT-%08d` minted at `RecordPayment`. Reprint is a read (§93). When the
 * payment is reversed the receipt is FLAGGED `REVERSED`, never deleted (§94).
 * Mutable (the flag) — `Rls::enable`, NOT append-only. `unique (tenant_id,
 * payment_id)` — one receipt per payment; `unique (tenant_id, receipt_number)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->uuid('financial_account_id');
            $table->uuid('patient_id');
            $table->uuid('payment_id');

            $table->string('receipt_number');                  // RCT-%08d
            $table->string('method');                          // snapshot from the payment
            $table->decimal('amount', 18, 4);
            $table->string('currency')->default('TZS');
            $table->string('status')->default('ISSUED');        // ISSUED | REVERSED

            $table->timestampTz('issued_at');
            $table->uuid('issued_by_user_id');
            $table->timestampTz('reversed_at')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'payment_id']);
            $table->unique(['tenant_id', 'receipt_number']);
        });

        Rls::enable('bill_receipts');
    }

    public function down(): void
    {
        Rls::disable('bill_receipts');
        Schema::dropIfExists('bill_receipts');
    }
};
