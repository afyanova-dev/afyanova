<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory critical results (14-laboratory-domain.md §53–§56, §104, §160–§161,
 * §228; ADR-161 D-D). Laboratory owns the FACT that a result is critical (§56);
 * Notifications delivers (§55, PHASE 12); Clinical acknowledges (§103 — recorded
 * here in Lab.3c pending a Clinical surface, the CC handover-acknowledgement
 * precedent).
 *
 * One record per result (`unique(tenant_id, result_id)`). `result_id` is an
 * intra-`lab_` FK. `order_id` / `patient_id` / `test_code` / `parameter_code`
 * are SNAPSHOTS. `severity` is open-coded normalised uppercase (§53). Free text
 * (`clinical_context`, `acknowledgement_note`) is ROW-ONLY — never event /
 * audit metadata (ADR-136 deny-list).
 *
 * Lifecycle (§160): INITIATED -> ACKNOWLEDGED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_critical_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('result_id')->constrained('lab_results');
            $table->uuid('order_id');
            $table->uuid('patient_id');
            $table->string('test_code');
            $table->string('parameter_code');

            $table->string('severity');
            $table->text('clinical_context')->nullable();          // free text, row-only (ADR-136)

            $table->string('status')->default('INITIATED');
            $table->uuid('identified_by_user_id');
            $table->timestampTz('identified_at');
            $table->uuid('acknowledged_by_user_id')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();      // free text, row-only (ADR-136)

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'result_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('lab_critical_results');
    }

    public function down(): void
    {
        Rls::disable('lab_critical_results');
        Schema::dropIfExists('lab_critical_results');
    }
};
