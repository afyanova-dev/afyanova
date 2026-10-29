<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.8) — claim adjudication record
 * (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 *
 * Aggregate root for claim adjudication:
 *   - Minted ADJ-%08d at creation (ADR-115, ADR-205).
 *   - Anchored immutably to a SUBMITTED claim (claim_id).
 *   - Status: APPROVED | PARTIALLY_APPROVED | DENIED | PENDED | REJECTED (§85).
 *   - Preserves explicit payer responsibility, patient responsibility, contractual
 *     adjustment, and denied amounts (Invariants 16, 17, 18, 19).
 *   - Preserves external payer response reference (Invariant 11).
 *   - Zero ledger writes, zero writes to bill_* tables (ADR-112, ADR-198 D-C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_adjudications', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('adjudication_number');
            $table->uuid('claim_id')->index();
            $table->uuid('payer_id')->index();

            $table->string('status', 32);

            $table->decimal('total_claimed_amount', 18, 4);
            $table->decimal('total_approved_amount', 18, 4)->default(0);
            $table->decimal('total_denied_amount', 18, 4)->default(0);
            $table->decimal('total_patient_responsibility', 18, 4)->default(0);
            $table->decimal('total_contractual_adjustment', 18, 4)->default(0);
            $table->string('currency', 3)->default('TZS');

            $table->date('adjudication_date');
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();

            $table->uuid('adjudicated_by_user_id')->nullable();
            $table->uuid('created_by_user_id');

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'adjudication_number']);
            $table->index(['tenant_id', 'claim_id']);
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        Rls::enable('ins_adjudications');
    }

    public function down(): void
    {
        Rls::disable('ins_adjudications');
        Schema::dropIfExists('ins_adjudications');
    }
};
