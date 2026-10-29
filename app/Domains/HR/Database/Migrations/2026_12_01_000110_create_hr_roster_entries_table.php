<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.3) — roster entries aggregate root
 * (25-hr-and-workforce-domain.md §6, §7, §8; ADR-206).
 *
 * Individual scheduled shift slots for staff members.
 * Lifecycle: DRAFT -> PUBLISHED -> COMPLETED | ABSENT | SWAPPED | CANCELLED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_roster_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('unit_id')->nullable()->index();

            $table->uuid('staff_id')->index();
            $table->uuid('shift_pattern_id')->index();

            $table->date('date')->index();
            $table->time('start_time');
            $table->time('end_time');

            $table->string('status', 32);
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
            $table->foreign('shift_pattern_id')->references('id')->on('hr_shift_patterns')->cascadeOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX hr_roster_entries_active_slot
             ON hr_roster_entries (tenant_id, staff_id, date, shift_pattern_id)
             WHERE status NOT IN ('CANCELLED', 'SWAPPED')"
        );

        Rls::enable('hr_roster_entries');
    }

    public function down(): void
    {
        Rls::disable('hr_roster_entries');
        DB::statement('DROP INDEX IF EXISTS hr_roster_entries_active_slot');
        Schema::dropIfExists('hr_roster_entries');
    }
};
