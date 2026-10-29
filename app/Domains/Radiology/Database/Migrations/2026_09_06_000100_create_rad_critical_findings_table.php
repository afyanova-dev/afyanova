<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology critical findings (15-radiology-domain.md §46–§48, §100–§102, §167,
 * §186, §213; ADR-169 D-B). One per critical observation on a radiology report.
 * Reuses the ADR-132 context+time shape (the Lab.3c `lab_critical_results`
 * precedent).
 *
 * **Multiple per report** — NO `unique` on `report_id` (§101 / §186 model the
 * critical finding at *finding* granularity, and a radiology report is
 * one-per-study unlike a lab result one-per-parameter). `report_id` FK +
 * study / order / patient / accession / modality SNAPSHOTS.
 *
 * `finding_summary` is free text, ROW-ONLY (the critical observation — ADR-136).
 * `severity` is open-coded normalised uppercase (§46 / §186). `status` (§167):
 * INITIATED → ACKNOWLEDGED. Radiology OWNS *that* a finding is critical (§47 /
 * §101); Notifications delivers (§55 / §101 — PHASE 12); Clinical acknowledges
 * (§100 / §167 — recorded here pending a Clinical surface, the Lab.3c / CC
 * handover-acknowledgement precedent). Escalation (§48 / §102) is deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_critical_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('report_id')->constrained('rad_radiology_reports');
            $table->uuid('study_id');   // snapshot
            $table->uuid('order_id');   // snapshot
            $table->uuid('patient_id'); // snapshot
            $table->string('accession_number'); // snapshot
            $table->string('modality_code');    // snapshot

            $table->text('finding_summary'); // the critical observation — free text, row-only (ADR-136)
            $table->string('severity');      // open-coded normalised uppercase (§46 / §186)

            $table->string('status')->default('INITIATED');
            $table->uuid('identified_by_user_id');
            $table->timestampTz('identified_at');
            $table->uuid('acknowledged_by_user_id')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable(); // free text, row-only

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'report_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('rad_critical_findings');
    }

    public function down(): void
    {
        Rls::disable('rad_critical_findings');
        Schema::dropIfExists('rad_critical_findings');
    }
};
