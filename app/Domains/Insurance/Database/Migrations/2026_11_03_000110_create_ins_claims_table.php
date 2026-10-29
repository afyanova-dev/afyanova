<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.7) — third-party payer reimbursement claim
 * (21-insurance-and-payer-domain.md §76–§82; ADR-204).
 *
 * Aggregate root for insurance claims:
 *   - Minted CLM-%08d at creation (ADR-115, Sponsor Ruling D1).
 *   - Anchored immutably to an ISSUED insured Billing invoice (invoice_id, ADR-201, Sponsor Ruling D2).
 *   - Status: DRAFT → READY → SUBMITTED / CANCELLED / SUPERSEDED.
 *   - Partial unique index on supersedes_claim_id guarantees single-successor invariant.
 *   - Zero ledger writes, zero writes to bill_* tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('claim_number');
            $table->uuid('invoice_id')->index();

            $table->uuid('patient_id')->index();
            $table->uuid('payer_id')->index();
            $table->uuid('plan_id')->index();
            $table->uuid('member_coverage_id')->index();

            $table->uuid('contract_id')->nullable()->index();
            $table->uuid('authorization_id')->nullable()->index();

            $table->string('status', 32);

            $table->decimal('total_claimed_amount', 18, 4)->default(0);
            $table->string('currency', 3)->default('TZS');

            $table->string('readiness_status', 32)->nullable();
            $table->timestampTz('readiness_checked_at')->nullable();
            $table->jsonb('readiness_notes')->nullable();

            $table->string('primary_diagnosis_code')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();

            $table->uuid('supersedes_claim_id')->nullable()->index();
            $table->uuid('superseded_by_claim_id')->nullable()->index();
            $table->timestampTz('superseded_at')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by_user_id')->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->uuid('created_by_user_id');

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        // ADR-204: Exactly one successor per superseded claim in a tenant.
        DB::statement(
            'create unique index ins_claims_one_successor_per_superseded
                 on ins_claims (tenant_id, supersedes_claim_id)
              where supersedes_claim_id is not null'
        );

        Rls::enable('ins_claims');
    }

    public function down(): void
    {
        Rls::disable('ins_claims');
        Schema::dropIfExists('ins_claims');
    }
};
