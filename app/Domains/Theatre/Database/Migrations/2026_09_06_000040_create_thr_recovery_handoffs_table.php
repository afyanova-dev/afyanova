<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery handoffs (17-theatre-domain.md §90–§92, §217; ADR-156 D-D) — the
 * documented transfer of a patient from theatre to a recovery destination,
 * with receiving-team acknowledgment. One per SurgicalCase
 * (UNIQUE(tenant_id, case_id)).
 *
 * ADDITIVE (ADR-156 D-A): `thr_surgical_cases.recovery_destination_type`
 * (folded at T.1) is unchanged and remains the legacy summary; this table is
 * authoritative for the acknowledged handoff. `patient_condition` /
 * `pending_actions` are ROW-ONLY (ADR-136). `receiving_team_ref` is a plain
 * nullable uuid with NO validation — Clinical care-team validation is
 * deferred (same as the CC episode coordinator; the `clinical_reason_ref`
 * precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_recovery_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');

            $table->string('destination_type');
            $table->text('patient_condition')->nullable();
            $table->text('pending_actions')->nullable();
            $table->uuid('receiving_team_ref')->nullable();

            $table->string('status')->default('INITIATED');
            $table->uuid('acknowledged_by_user_id')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();

            $table->uuid('initiated_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'case_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('thr_recovery_handoffs');
    }

    public function down(): void
    {
        Rls::disable('thr_recovery_handoffs');
        Schema::dropIfExists('thr_recovery_handoffs');
    }
};
