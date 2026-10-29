<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory results (14-laboratory-domain.md §45–§70, §151, §157, §227–§229;
 * ADR-159 D-D). One row per measurable parameter (§13) — a CBC produces one
 * `lab_results` row per analyte. Reuses the ADR-132 context+time shape.
 *
 * `order_id` / `order_item_id` / `specimen_id` are intra-`lab_` FKs. `patient_id`
 * is a SNAPSHOT (result-to-patient matching, §227). `test_code` /
 * `test_definition_id` snapshot the catalog at result time.
 *
 * A result is NUMERIC, CODED or TEXT (`value_type` — §46–§48): exactly one of
 * `value_numeric` / `value_code` / `value_text` carries the value. `value_text`
 * is free-text clinical content (§48 microscopy findings) — ROW-ONLY, never
 * event / audit metadata (the ADR-136 deny-list). `reference_low` /
 * `reference_high` / `reference_text` / `reference_range_source` are the
 * SNAPSHOT of the range applied (§52 — a historical result must not silently
 * re-interpret when the catalog range later changes); the applicable range is
 * carried on the `RecordResult` command (from the catalog's
 * `parameters[].reference_ranges` prefill or the analyzer). `abnormal_flag` is
 * open-coded (§49), computed at entry from the numeric value vs the range.
 *
 * Lab.3a lifecycle (§157): ENTERED -> REVIEWED -> VERIFIED -> RELEASED.
 * `CANCELLED` / `AMENDED` + `amendment_count` + `cancellation_reason` arrive
 * with Lab.3b (ADR-160 — the append-only `lab_result_amendments` carries the
 * before/after; `amendment_count` is bumped on each amend/cancel so the
 * optimistic-lock UPDATE always dirties the row — the T.2 precedent).
 * `release_hold_reason` is a generic release block checked by
 * `LabResultReleaseGate`; Lab.5 (QC) sets it to `QC_FAILED` for affected
 * results (reserved here, wired there). `result_source` is `MANUAL` in
 * Lab.3a — analyzer/import is Lab.6+.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('order_id')->constrained('lab_orders');
            $table->foreignUuid('order_item_id')->constrained('lab_order_items');
            $table->foreignUuid('specimen_id')->constrained('lab_specimens');
            $table->uuid('patient_id'); // snapshot (§227)

            $table->uuid('test_definition_id');
            $table->string('test_code');
            $table->string('parameter_code');
            $table->string('parameter_name');

            $table->string('value_type'); // NUMERIC | CODED | TEXT
            $table->decimal('value_numeric', 18, 6)->nullable();
            $table->string('value_code')->nullable();
            $table->text('value_text')->nullable();               // free text, row-only (ADR-136)
            $table->string('unit')->nullable();

            $table->decimal('reference_low', 18, 6)->nullable();
            $table->decimal('reference_high', 18, 6)->nullable();
            $table->string('reference_text')->nullable();
            $table->string('reference_range_source')->nullable();
            $table->string('abnormal_flag')->nullable();          // open-coded (§49)

            $table->string('result_source')->default('MANUAL');   // §65 — analyzer/import is Lab.6+
            $table->string('method')->nullable();                 // §66

            $table->string('status')->default('ENTERED');
            $table->string('release_hold_reason')->nullable();    // generic release block; Lab.5 QC sets QC_FAILED
            $table->unsignedInteger('amendment_count')->default(0); // Lab.3b — bumped on each amend/cancel (dirties the row)
            $table->text('cancellation_reason')->nullable();       // Lab.3b — free text, row-only (ADR-136)

            $table->uuid('entered_by_user_id');
            $table->timestampTz('entered_at');
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('verified_by_user_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('released_by_user_id')->nullable();
            $table->timestampTz('released_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'specimen_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('lab_results');
    }

    public function down(): void
    {
        Rls::disable('lab_results');
        Schema::dropIfExists('lab_results');
    }
};
