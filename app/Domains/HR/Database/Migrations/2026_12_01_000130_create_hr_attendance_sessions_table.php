<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.4) — attendance sessions aggregate root
 * (25-hr-and-workforce-domain.md §6, §7.11; ADR-206).
 *
 * Derived and rebuildable work shift sessions.
 * Lifecycle: OPEN -> CLOSED -> RECONCILED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('staff_id')->index();
            $table->uuid('roster_entry_id')->nullable()->index();

            $table->timestampTz('started_at')->index();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->string('exception', 32)->default('NONE');
            $table->jsonb('source_event_ids')->default('[]');
            $table->string('status', 20)->default('OPEN'); // OPEN, CLOSED, RECONCILED

            $table->text('reconciliation_note')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestampTz('reconciled_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
            $table->foreign('roster_entry_id')->references('id')->on('hr_roster_entries')->nullOnDelete();

            $table->index(['tenant_id', 'staff_id', 'started_at'], 'hr_attendance_sessions_staff_started_idx');
            $table->index(['tenant_id', 'facility_id', 'started_at'], 'hr_attendance_sessions_facility_started_idx');
        });

        Rls::enable('hr_attendance_sessions');
    }

    public function down(): void
    {
        Rls::disable('hr_attendance_sessions');
        Schema::dropIfExists('hr_attendance_sessions');
    }
};
