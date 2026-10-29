<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surgical procedures (17-theatre-domain.md §10, §213; ADR-156 D-C) — the
 * operation(s) performed during a surgical case, multi-per-case.
 *
 * ADDITIVE (ADR-156 D-A): `thr_surgical_cases` keeps its folded
 * `actual_procedure` / `findings` / `outcome` / `complications` columns as a
 * legacy single-procedure summary; this table is authoritative for the
 * detailed per-procedure record. A consolidation refactor is the future T.5.
 *
 * `findings` / `complications` are ROW-ONLY (ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_surgical_procedures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');

            $table->unsignedInteger('sequence');
            $table->string('planned_description')->nullable();
            $table->string('actual_description')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->uuid('primary_surgeon_user_id')->nullable();
            $table->string('outcome')->nullable();
            $table->text('findings')->nullable();
            $table->text('complications')->nullable();

            $table->string('status')->default('PLANNED');

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'case_id', 'sequence']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('thr_surgical_procedures');
    }

    public function down(): void
    {
        Rls::disable('thr_surgical_procedures');
        Schema::dropIfExists('thr_surgical_procedures');
    }
};
