<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.4 — ADR-201 D-B) — the Insurance coverage split on a
 * Billing invoice. ADDITIVE, NULLABLE, NON-BREAKING (amends ADR-190 by
 * pointer).
 *
 * The estimate is ATTRIBUTION on the invoice row — it posts NO ledger entry
 * (INV-1 held) and never touches `bill_charges` (INV-2 / INV-3). A SELF_PAY
 * invoice keeps every new column null / default 0 and reads byte-identically.
 * `patient_responsibility` defaults to 0; the reader falls back to `net` when
 * `payer_id is null`. Frozen at ISSUE alongside `net`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_invoices', function (Blueprint $table): void {
            $table->uuid('payer_id')->nullable();               // a real ins_payers id when insured
            $table->uuid('member_coverage_id')->nullable();
            $table->uuid('coverage_plan_id')->nullable();
            $table->decimal('payer_responsibility', 18, 4)->default(0);
            $table->decimal('patient_responsibility', 18, 4)->default(0);
            $table->string('coverage_status')->nullable();
            $table->string('coverage_source')->nullable();
            $table->string('coverage_confidence')->nullable();
            $table->timestampTz('coverage_evaluated_at')->nullable();
        });

        Schema::table('bill_invoice_lines', function (Blueprint $table): void {
            $table->string('service_category')->nullable();     // SNAPSHOT at AddInvoiceLine
            $table->decimal('payer_responsibility', 18, 4)->nullable();
            $table->decimal('patient_responsibility', 18, 4)->nullable();
            $table->string('coverage_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bill_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'payer_id', 'member_coverage_id', 'coverage_plan_id',
                'payer_responsibility', 'patient_responsibility',
                'coverage_status', 'coverage_source', 'coverage_confidence', 'coverage_evaluated_at',
            ]);
        });

        Schema::table('bill_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'service_category', 'payer_responsibility', 'patient_responsibility', 'coverage_status',
            ]);
        });
    }
};
