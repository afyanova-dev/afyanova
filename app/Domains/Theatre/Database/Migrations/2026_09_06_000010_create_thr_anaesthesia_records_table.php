<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anaesthesia records — Theatre-owned anaesthesia documentation
 * (17-theatre-domain.md §36–§40, §82, §83; ADR-155 D-B).
 *
 * One row per SurgicalCase (UNIQUE(tenant_id, case_id) — the T.2
 * thr_operative_notes precedent). `content` is a governance-configurable
 * jsonb blob (§82 note); ROW-ONLY, never in an event or audit metadata
 * (ADR-136 deny-list). `anaesthesia_type` is open-coded, normalised uppercase
 * by the command handler — §38's `OTHER` value and its "must be governed"
 * caveat rule out a hard enum (rajani ruling 2026-09-06).
 *
 * NOT a `clin_notes` row — Theatre-owned, `thr_` schema, its own keys and
 * lifecycle (§37).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_anaesthesia_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');

            $table->string('anaesthesia_type');
            $table->uuid('provider_user_id')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->jsonb('content')->default('{}');
            $table->string('outcome')->nullable();

            $table->string('status')->default('DRAFT');
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

        Rls::enable('thr_anaesthesia_records');
    }

    public function down(): void
    {
        Rls::disable('thr_anaesthesia_records');
        Schema::dropIfExists('thr_anaesthesia_records');
    }
};
