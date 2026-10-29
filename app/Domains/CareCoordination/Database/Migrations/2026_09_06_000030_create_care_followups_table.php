<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-ups (26-care-coordination-domain.md §6, §8; ADR-154 D-B) — "a
 * follow-up is due; was it kept?". Reuses the ADR-132 context+time shape.
 *
 * `facility_id` is the source encounter's (ADR-132 originating context).
 * `booked_appointment_id` / `resulting_encounter_id` are nullable opaque
 * uuids with NO FK — Scheduling is unbuilt and cross-prefix FKs are forbidden
 * (IC §10, the `clinical_reason_ref` precedent). `reason` / `instructions`
 * are ROW-ONLY (ADR-136 deny-list).
 *
 * `status` carries the full §8 vocabulary; SCHEDULED is declared but not
 * wired in CC.3a (it needs a real Scheduling booking — the CC.1
 * partial-lifecycle-traversal precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_followups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('patient_id');
            $table->uuid('source_encounter_id');

            $table->date('due_from');
            $table->date('due_to');
            $table->text('reason')->nullable();
            $table->text('instructions')->nullable();

            $table->uuid('booked_appointment_id')->nullable();
            $table->uuid('resulting_encounter_id')->nullable();

            $table->string('status')->default('PENDING');
            $table->timestampTz('outcome_recorded_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'facility_id', 'status', 'due_from']);
        });

        Rls::enable('care_followups');
    }

    public function down(): void
    {
        Rls::disable('care_followups');
        Schema::dropIfExists('care_followups');
    }
};
