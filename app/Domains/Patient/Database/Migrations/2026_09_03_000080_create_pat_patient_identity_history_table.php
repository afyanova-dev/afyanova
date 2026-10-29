<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 (increment A.6) — patient identity-merge history / mapping
 * (10-patient-domain.md §24, §144, §152; ADR-118, ADR-128).
 *
 * Its OWN small tenant-scoped aggregate (ADR-128 M-4/M-5) — one row per merge,
 * `ACTIVE` on merge, `REVERSED` on `RequestUnmerge`, never deleted (Invariants
 * 15, 27, 28). Serves both the resolvable identity mapping and the immutable
 * queryable merge record (`GetPatientMergeHistory`). `patient_id` references
 * are plain indexed uuids with NO database foreign key (shipped precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pat_patient_identity_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('merge_id');

            $table->uuid('surviving_patient_id')->index();
            $table->uuid('non_surviving_patient_id')->index();
            $table->string('non_surviving_patient_number');          // preserved (ADR-128)
            $table->string('non_surviving_prior_status');            // PatientStatus — restore target for unmerge

            $table->text('reason');
            $table->text('evidence');
            $table->uuid('merged_by_user_id');
            $table->timestampTz('merged_at');

            $table->string('status')->default('ACTIVE');             // MergeHistoryStatus enum
            $table->uuid('unmerged_by_user_id')->nullable();
            $table->timestampTz('unmerged_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'merge_id']);               // one history row per merge
            $table->index(['tenant_id', 'non_surviving_patient_id', 'status']); // activeForNonSurviving lookup
        });

        Rls::enable('pat_patient_identity_history');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_identity_history');
        Schema::dropIfExists('pat_patient_identity_history');
    }
};
