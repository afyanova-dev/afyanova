<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.5) — Pre-Authorization
 * (21-insurance-and-payer-domain.md §56–§65, §248; Authorization Invariants
 * §276 Inv 23–25; ADR-202 D-A).
 *
 * Local record of the payer's pre-authorization approval decision.
 * Set-once: member_coverage_id, patient_id, plan_id, payer_id, service_category.
 * Terminal states: DENIED, EXPIRED, CANCELLED. Row is never deleted (§64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('authorization_number', 32);         // AUT-%08d
            $table->uuid('member_coverage_id')->index();        // SET-ONCE
            $table->uuid('patient_id')->index();                // SET-ONCE — copied from member coverage
            $table->uuid('plan_id')->index();                   // SET-ONCE — copied from member coverage
            $table->uuid('payer_id')->index();                  // SET-ONCE — copied from member coverage
            $table->string('service_category', 32);             // SET-ONCE — CoveredServiceCategory VO

            $table->text('service_description')->nullable();    // free text (§59 / §75)
            $table->string('diagnosis_code', 32)->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('provider_user_id')->nullable();

            $table->decimal('requested_quantity', 12, 4)->nullable();
            $table->decimal('requested_amount', 12, 4)->nullable();

            $table->string('status', 32)->default('REQUESTED'); // AuthorizationStatus VO
            $table->string('external_authorization_number', 64)->nullable();
            $table->decimal('approved_quantity', 12, 4)->nullable();
            $table->decimal('approved_amount', 12, 4)->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('decision_reason')->nullable();

            $table->uuid('requested_by_user_id');
            $table->timestampTz('requested_at');

            $table->uuid('decided_by_user_id')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampTz('expired_at')->nullable();

            $table->uuid('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'authorization_number']);
            $table->index(['tenant_id', 'patient_id', 'service_category']);
            $table->index(['tenant_id', 'status']);
        });

        Rls::enable('ins_authorizations');
    }

    public function down(): void
    {
        Rls::disable('ins_authorizations');
        Schema::dropIfExists('ins_authorizations');
    }
};
