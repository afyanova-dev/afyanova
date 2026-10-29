<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.8) — payer remittance advice
 * (21-insurance-and-payer-domain.md §98–§103; ADR-205).
 *
 * Aggregate root for payer remittance advice batches (ERA, EFT statement, check batch):
 *   - Minted REM-%08d at creation (ADR-115, ADR-205).
 *   - Tracks total payer settlement amount and reconciled vs unreconciled balance.
 *   - Status: RECORDED | RECONCILED | DISCREPANT.
 *   - Settlement fact: actual money posted into Billing financial accounts via the
 *     reconciliation seam (ADR-198 D-C). Zero ledger writes in Insurance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_remittances', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('remittance_number');
            $table->uuid('payer_id')->index();

            $table->string('payment_reference');
            $table->string('payment_method', 32)->default('BANK_TRANSFER');
            $table->date('remittance_date');

            $table->decimal('total_amount', 18, 4);
            $table->decimal('reconciled_amount', 18, 4)->default(0);
            $table->decimal('unreconciled_amount', 18, 4);

            $table->string('status', 32);
            $table->string('currency', 3)->default('TZS');
            $table->text('notes')->nullable();

            $table->uuid('created_by_user_id');

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'remittance_number']);
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        Rls::enable('ins_remittances');
    }

    public function down(): void
    {
        Rls::disable('ins_remittances');
        Schema::dropIfExists('ins_remittances');
    }
};
