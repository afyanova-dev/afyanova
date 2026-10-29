<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology report amendments (15-radiology-domain.md §44, §96, §158, §164;
 * ADR-168 D-B). One append-only row per CORRECTION / ADDENDUM of a
 * `rad_radiology_reports` row — the historical before/after trail (§45 "released
 * report != freely editable"; §96 versioning; §158 correction audit).
 *
 * Plain append-only child — NOT an AggregateRoot: the `RadiologyReport`
 * aggregate emits `RadiologyReportAmended` / `RadiologyReportCancelled`, this
 * table just records the change (the `lab_result_amendments` /
 * `thr_operative_note_amendments` precedent). `Rls::enableAppendOnly`
 * (RESTRICTIVE — no UPDATE / DELETE).
 *
 * `version` is present for schema/conformance shape only (ConformanceCommand
 * check 3b) — stays 0 for the row's life, NOT optimistic concurrency (the
 * standing `governance-rulings` interpretation).
 *
 * `reason` is free text — row-only + on the audit row, never in the event
 * (ADR-136 deny-list). `previous_*` / `new_*` capture the text snapshot on both
 * sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_report_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('report_id')->constrained('rad_radiology_reports');
            $table->unsignedInteger('sequence');
            $table->string('amendment_type'); // CORRECTION | ADDENDUM (§44 / §164)

            $table->string('previous_status');
            $table->string('new_status');

            $table->text('previous_findings_text')->nullable();
            $table->text('previous_impression_text')->nullable();
            $table->text('previous_recommendations_text')->nullable();
            $table->text('new_findings_text')->nullable();
            $table->text('new_impression_text')->nullable();
            $table->text('new_recommendations_text')->nullable();

            $table->text('reason'); // free text, row-only + audit (ADR-136)

            $table->uuid('amended_by_user_id');
            $table->timestampTz('amended_at');

            $table->unsignedBigInteger('version')->default(0); // shape/conformance-only (governance-rulings)
            $table->timestampTz('created_at')->nullable();

            $table->unique(['tenant_id', 'report_id', 'sequence']);
            $table->index(['tenant_id', 'report_id']);
        });

        Rls::enableAppendOnly('rad_report_amendments');
    }

    public function down(): void
    {
        Rls::disable('rad_report_amendments');
        Schema::dropIfExists('rad_report_amendments');
    }
};
