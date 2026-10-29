<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.2) — the member-coverage record
 * (21-insurance-and-payer-domain.md §13, §14, §17–§23, §247; Invariant 2;
 * ADR-199 D-A).
 *
 * A patient's participation in a payer plan. `patient_id` is verified via
 * `PatientApi::patientExists` in the handler (a cross-domain read, no FK).
 * `plan_id` / `payer_id` are plain references (no DB foreign key — the Billing
 * precedent), both SET-ONCE; `payer_id` is copied from the plan at
 * registration. The external `member_number` / `policy_number` / `card_number`
 * never replace internal identity (§16). Mutable working record —
 * `Rls::enable` only. Terminal state TERMINATED; the row is never deleted
 * (§319). NO events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_member_coverages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('patient_id');
            $table->uuid('plan_id');                            // SET-ONCE — no FK
            $table->uuid('payer_id');                           // SET-ONCE — copied from the plan

            $table->string('member_number')->nullable();        // external payer identifiers (§15 / §16)
            $table->string('policy_number')->nullable();
            $table->string('card_number')->nullable();

            $table->string('relationship');                     // PRIMARY_MEMBER | SPOUSE | CHILD | DEPENDENT | OTHER_DEPENDENT
            $table->uuid('principal_member_coverage_id')->nullable();   // §18 — NULL for PRIMARY_MEMBER
            $table->string('priority');                         // PRIMARY | SECONDARY | TERTIARY

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('status')->default('ACTIVE');        // ACTIVE | TERMINATED
            $table->timestampTz('terminated_at')->nullable();
            $table->uuid('terminated_by_user_id')->nullable();
            $table->string('termination_reason')->nullable();

            $table->uuid('created_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'plan_id', 'status']);
            $table->index(['tenant_id', 'payer_id', 'status']);
        });

        // ADR-199 D-A / §21 / §143 — one ACTIVE coverage per priority per patient.
        DB::statement(
            "create unique index ins_member_coverages_one_active_per_priority
                 on ins_member_coverages (tenant_id, patient_id, priority)
              where status = 'ACTIVE'"
        );

        Rls::enable('ins_member_coverages');
    }

    public function down(): void
    {
        Rls::disable('ins_member_coverages');
        Schema::dropIfExists('ins_member_coverages');
    }
};
