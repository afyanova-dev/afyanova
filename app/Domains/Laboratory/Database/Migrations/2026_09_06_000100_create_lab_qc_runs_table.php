<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory quality-control runs (14-laboratory-domain.md §83–§85, §153, §159;
 * ADR-163 D-D). QC results are laboratory-owned and are NOT patient results
 * (§84) — a failed run may HOLD affected patient results from release (§85,
 * §159), via the `lab_results.release_hold_reason` hook reserved in ADR-159.
 *
 * `analyzer_ref` is an open string — there is no equipment / asset domain
 * (§86–§87). `test_definition_id` is a nullable plain uuid, NO FK (the analyte
 * under QC; nullable for an analyzer-wide run). `expected_*` / `observed_value`
 * are decimals; `status` (PASS | FAIL | WARNING) is computed at record time
 * from the observed value vs the expected range. `review_note` is free text,
 * ROW-ONLY (ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_qc_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->string('analyzer_ref');
            $table->uuid('test_definition_id')->nullable();
            $table->string('test_code')->nullable();
            $table->string('control_material');
            $table->string('control_level')->nullable();

            $table->decimal('expected_low', 18, 6)->nullable();
            $table->decimal('expected_high', 18, 6)->nullable();
            $table->decimal('observed_value', 18, 6);
            $table->string('unit')->nullable();

            $table->string('status'); // PASS | FAIL | WARNING

            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->uuid('performed_by_user_id');
            $table->timestampTz('performed_at');

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'facility_id', 'test_definition_id']);
        });

        Rls::enable('lab_qc_runs');
    }

    public function down(): void
    {
        Rls::disable('lab_qc_runs');
        Schema::dropIfExists('lab_qc_runs');
    }
};
