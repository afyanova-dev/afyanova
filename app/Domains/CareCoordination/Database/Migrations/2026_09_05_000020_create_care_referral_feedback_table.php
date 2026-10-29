<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral feedback (26-care-coordination-domain.md §6; ADR-151 D-D) — its
 * own lifecycle, independent of the referral's (Invariant 7.7).
 *
 * `sequence` reserved for the doc's own "feedback may arrive in parts"
 * richness; CC.1 creates exactly one feedback row per referral (sequence=1,
 * auto-created at AcceptReferral) progressing through its own status — full
 * multi-entry append history is a T.2+-style deferral, the same partial-
 * traversal precedent already accepted for Theatre's 13-state lifecycle.
 *
 * No new concurrency mechanism needed (ADR-151 D-E) — a referral's/feedback's
 * state lives on one row each; ordinary optimistic version-conflict
 * detection is sufficient (unlike Bed & Ward/Theatre's shared physical
 * resources).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('referral_id');
            $table->unsignedInteger('sequence')->default(1);
            $table->string('kind')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->string('received_via')->nullable();
            $table->text('summary')->nullable();
            $table->text('outcome')->nullable();
            $table->string('status')->default('EXPECTED');

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'referral_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('care_referral_feedback');
    }

    public function down(): void
    {
        Rls::disable('care_referral_feedback');
        Schema::dropIfExists('care_referral_feedback');
    }
};
