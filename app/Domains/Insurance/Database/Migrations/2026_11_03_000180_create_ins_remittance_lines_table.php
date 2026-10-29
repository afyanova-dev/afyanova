<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.8) — remittance line item
 * (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 *
 * Line item on a remittance advice matching an individual claim:
 *   - Status: MATCHED | UNMATCHED | DISCREPANT.
 *   - Captures claimed amount, paid amount, contractual adjustment, patient responsibility,
 *     and denied amount per claim.
 *   - Flags underpayment or overpayment discrepancies (§189, §190).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_remittance_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('remittance_id')->index();
            $table->unsignedInteger('line_number');

            $table->uuid('claim_id')->nullable()->index();
            $table->uuid('adjudication_id')->nullable()->index();
            $table->string('external_claim_reference')->nullable();

            $table->decimal('claimed_amount', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('contractual_adjustment', 18, 4)->default(0);
            $table->decimal('patient_responsibility', 18, 4)->default(0);
            $table->decimal('denied_amount', 18, 4)->default(0);

            $table->string('status', 32);
            $table->string('discrepancy_reason')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'remittance_id', 'line_number']);
            $table->index(['tenant_id', 'claim_id']);
        });

        Rls::enable('ins_remittance_lines');
    }

    public function down(): void
    {
        Rls::disable('ins_remittance_lines');
        Schema::dropIfExists('ins_remittance_lines');
    }
};
