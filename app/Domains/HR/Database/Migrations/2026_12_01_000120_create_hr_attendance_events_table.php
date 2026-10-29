<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.4) — attendance events aggregate root
 * (25-hr-and-workforce-domain.md §6, §7.11; ADR-206).
 *
 * Append-only immutable stream of clocking events.
 * Idempotent on (tenant_id, external_event_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('staff_id')->index();

            $table->string('source', 20); // DEVICE, MOBILE, MANUAL, SYSTEM
            $table->string('device_ref', 100)->nullable();
            $table->string('external_event_id', 150)->nullable();
            $table->string('direction', 20); // IN, OUT, BREAK_OUT, BREAK_IN
            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('ingested_at');
            $table->jsonb('raw_payload')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX hr_attendance_events_external_unique
             ON hr_attendance_events (tenant_id, external_event_id)
             WHERE external_event_id IS NOT NULL'
        );

        Schema::table('hr_attendance_events', function (Blueprint $table): void {
            $table->index(['tenant_id', 'staff_id', 'occurred_at'], 'hr_attendance_events_staff_occurred_idx');
            $table->index(['tenant_id', 'facility_id', 'occurred_at'], 'hr_attendance_events_facility_occurred_idx');
        });

        Rls::enable('hr_attendance_events');
    }

    public function down(): void
    {
        Rls::disable('hr_attendance_events');
        DB::statement('DROP INDEX IF EXISTS hr_attendance_events_external_unique');
        Schema::dropIfExists('hr_attendance_events');
    }
};
