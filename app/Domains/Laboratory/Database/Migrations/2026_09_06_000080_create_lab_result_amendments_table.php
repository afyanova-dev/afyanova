<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory result amendments (14-laboratory-domain.md §61–§64, §137, §181;
 * ADR-160 D-D). One append-only row per correction / cancellation of a
 * `lab_results` row — the historical before/after trail (§62 "no silent result
 * editing"; §64 versioning; §137 correction audit).
 *
 * Plain append-only child — NOT an AggregateRoot: the `LabResult` aggregate
 * emits `LabResultAmended` / `LabResultCancelled`, this table just records the
 * change (the `thr_operative_note_amendments` / `clin_note_amendments`
 * precedent). `Rls::enableAppendOnly` (RESTRICTIVE — no UPDATE / DELETE).
 *
 * `version` is present for schema/conformance shape only (ConformanceCommand
 * check 3b) — it stays 0 for the row's life and is NOT optimistic concurrency
 * (the standing `governance-rulings` interpretation).
 *
 * `previous_*` / `new_*` capture the value snapshot on both sides.
 * `reason` is free text — row-only + on the audit row, never in the event
 * (ADR-136 deny-list). `replacement_result_id` (nullable, no FK) links a
 * CANCELLATION to the fresh result that replaces it (§63).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_result_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('result_id')->constrained('lab_results');
            $table->unsignedInteger('sequence');
            $table->string('amendment_type'); // CORRECTION | CANCELLATION

            $table->string('previous_status');
            $table->string('new_status');

            $table->string('previous_value_type')->nullable();
            $table->decimal('previous_value_numeric', 18, 6)->nullable();
            $table->string('previous_value_code')->nullable();
            $table->text('previous_value_text')->nullable();

            $table->string('new_value_type')->nullable();
            $table->decimal('new_value_numeric', 18, 6)->nullable();
            $table->string('new_value_code')->nullable();
            $table->text('new_value_text')->nullable();

            $table->text('reason'); // free text, row-only + audit (ADR-136)
            $table->uuid('replacement_result_id')->nullable(); // §63 — no FK

            $table->uuid('amended_by_user_id');
            $table->timestampTz('amended_at');

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only (governance-rulings)
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'result_id', 'sequence']);
            $table->index(['tenant_id', 'result_id']);
        });

        Rls::enableAppendOnly('lab_result_amendments');
    }

    public function down(): void
    {
        Rls::disable('lab_result_amendments');
        Schema::dropIfExists('lab_result_amendments');
    }
};
