<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intra-operative case events / surgical timing (17-theatre-domain.md §84–§87;
 * ADR-156 D-E) — an append-only timeline of coded events for a surgical case
 * (Patient In OR, Incision, Procedure Start/End, Anaesthesia Start/End, …).
 *
 * ADDITIVE. `event_type` is open-coded, normalised uppercase. `note` is
 * ROW-ONLY (ADR-136). Append-only in behaviour; modelled as a small
 * AggregateRoot so `save()` drains the `CaseEventRecorded` event to the
 * outbox (the CC.2 `CareEpisodeEncounter` precedent — a sketch-"plain child"
 * that needs an event becomes an aggregate). `Rls::enableAppendOnly` blocks
 * UPDATE/DELETE at the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->uuid('case_id');
            $table->string('event_type');
            $table->timestampTz('event_at');
            $table->text('note')->nullable();

            $table->uuid('recorded_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'case_id', 'event_at']);
        });

        Rls::enable('thr_case_events');
        Rls::enableAppendOnly('thr_case_events');
    }

    public function down(): void
    {
        Rls::disable('thr_case_events');
        Schema::dropIfExists('thr_case_events');
    }
};
