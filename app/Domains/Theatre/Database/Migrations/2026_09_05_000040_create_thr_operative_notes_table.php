<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operative notes — theatre-specific surgical documentation
 * (17-theatre-domain.md §77–§83, §216; ADR-152 D-C).
 *
 * One row per SurgicalCase (UNIQUE(tenant_id, case_id) — ADR-152 D-E).
 * `content` is a governance-configurable jsonb blob (§77 "clinically
 * governed"); ROW-ONLY, never in an event or audit metadata (ADR-136
 * deny-list, exactly like T.1's findings/outcome/complications).
 *
 * NOT a `clin_notes` row — this is Theatre-owned, the REVIEWED lifecycle
 * stage has no place in ClinicalNoteType (ADR-150's own rejected
 * alternative, ratified by ADR-152 D-K).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_operative_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');

            $table->jsonb('content')->default('{}');
            $table->string('status')->default('DRAFT');

            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('signed_by_user_id')->nullable();
            $table->timestampTz('signed_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'case_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('thr_operative_notes');
    }

    public function down(): void
    {
        Rls::disable('thr_operative_notes');
        Schema::dropIfExists('thr_operative_notes');
    }
};
