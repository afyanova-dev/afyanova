<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.1) — the Prescription record
 * (16-pharmacy-domain.md §6, §19, §20, §21, §111; ADR-181 D-C).
 *
 * Pharmacy creates and owns the authoritative Prescription RECORD on receipt of
 * Clinical's `clinical.PrescriptionIssued` event (Clinical owns the prescribing
 * DECISION). A `RECEIVED` stub is written from the PHI-minimal event payload
 * only (ADR-181 C1); the immutable clinical SNAPSHOT (medication / dose /
 * quantity / …) is filled SET-ONCE by an authenticated `AcceptPrescription`
 * that fetches the decision through the protected `ClinicalApi::getPrescribingDecision`.
 *
 * Lifecycle §250 subset: RECEIVED → ACTIVE, terminal CANCELLED (ADR-181 C2 —
 * the §249 VALIDATING/VALIDATED/REJECTED/READY states are the Pharmacy Order
 * workflow, deferred). NO `EXPIRED` state / `valid_until` column (ADR-181 C5 /
 * ADR-179 — "don't add a column nothing writes").
 *
 * Mutable working record — `Rls::enable` only, NOT append-only. Facility-scoped
 * to the ORIGINATING (prescribing) facility. `status` is an open-coded string
 * constant on the model (the `StockAdjustment` precedent, NOT a PHP enum).
 * `cancel_reason` free text is ROW-ONLY (the ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_prescriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                       // = the ORIGINATING facility

            // the Clinical link — plain uuid, NO FK; UNIQUE per tenant = the
            // listener idempotency backstop (ADR-181 D-C / D-E).
            $table->uuid('prescribing_decision_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');

            $table->string('prescription_number');            // RX-%08d, tenant-wide unique (ADR-115)
            $table->uuid('author_user_id');                   // the prescriber (event authorUserId)
            $table->string('change_type')->nullable();        // null | CORRECTION — dormant (ADR-181 C7)

            // coded event fields — present from RECEIVED
            $table->string('medication_code_system')->nullable();
            $table->string('route')->nullable();
            $table->string('urgency')->nullable();

            // the immutable clinical SNAPSHOT — null until AcceptPrescription, then SET-ONCE
            $table->text('medication_name')->nullable();
            $table->string('medication_code')->nullable();
            $table->string('dose')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->string('prescribed_quantity')->nullable();
            $table->text('dosing_instruction')->nullable();

            $table->string('status')->default('RECEIVED');    // RECEIVED | ACTIVE | CANCELLED
            $table->uuid('accepted_by_user_id')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();
            $table->text('cancel_reason')->nullable();        // ROW-ONLY (ADR-136)

            $table->timestampTz('occurred_at');              // clinical issue time (event occurredAt)
            $table->timestampTz('recorded_at');              // ingestion time

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'prescribing_decision_id']); // ADR-181 D-C — idempotency backstop
            $table->unique(['tenant_id', 'prescription_number']);     // ADR-115
            $table->index(['tenant_id', 'facility_id', 'status']);    // the pharmacy queue
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('rx_prescriptions');
    }

    public function down(): void
    {
        Rls::disable('rx_prescriptions');
        Schema::dropIfExists('rx_prescriptions');
    }
};
