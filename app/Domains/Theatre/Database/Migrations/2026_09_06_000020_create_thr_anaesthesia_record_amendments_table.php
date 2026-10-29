<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anaesthesia-record amendments (17-theatre-domain.md §81 analogue; ADR-155
 * D-B). The `thr_operative_note_amendments` precedent EXACTLY.
 *
 * Plain, append-only child table — NOT an AggregateRoot. `Rls::enableAppendOnly`
 * blocks UPDATE/DELETE at the DB. `version` is SHAPE / CONFORMANCE-ONLY (the
 * ADR-152 D-C ratified interpretation — it satisfies the tenant-scoped-table
 * invariant enforced by ConformanceCommand, stays 0, is NOT optimistic
 * concurrency on the rows). `amendment_text` / `reason` are ROW-ONLY
 * (ADR-136 deny-list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_anaesthesia_record_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('anaesthesia_record_id');
            $table->unsignedInteger('sequence');
            $table->text('amendment_text');
            $table->text('reason');
            $table->uuid('created_by_user_id');
            $table->timestampTz('created_at');

            $table->unsignedBigInteger('version')->default(0);

            $table->index(['tenant_id', 'anaesthesia_record_id']);
        });

        Rls::enable('thr_anaesthesia_record_amendments');
        Rls::enableAppendOnly('thr_anaesthesia_record_amendments');
    }

    public function down(): void
    {
        Rls::disable('thr_anaesthesia_record_amendments');
        Schema::dropIfExists('thr_anaesthesia_record_amendments');
    }
};
