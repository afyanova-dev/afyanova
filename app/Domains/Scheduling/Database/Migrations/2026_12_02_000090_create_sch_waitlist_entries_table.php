<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.4) — waitlist entries table
 * (22-scheduling-and-appointments-domain.md §23–§27, §115–§117).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_waitlist_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('waitlist_number', 32);
            $table->uuid('patient_id')->index(); // External reference to Patient domain (no cross-domain FK, ADR-093)
            $table->uuid('appointment_type_id')->index();
            $table->uuid('resource_id')->nullable()->index(); // Optional preferred provider/room

            $table->string('priority', 32)->default('ROUTINE')->index(); // ROUTINE, URGENT, EMERGENCY
            $table->string('status', 32)->default('ACTIVE')->index();     // ACTIVE, OFFERED, BOOKED, EXPIRED, CANCELLED

            $table->date('preferred_date_start')->nullable();
            $table->date('preferred_date_end')->nullable();
            $table->string('notes', 500)->nullable();

            $table->uuid('offered_slot_id')->nullable()->index();
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('offered_until')->nullable();

            $table->uuid('booked_appointment_id')->nullable()->index();

            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'waitlist_number'], 'sch_waitlist_tenant_number_unique');
            $table->index(['tenant_id', 'facility_id', 'status', 'priority'], 'sch_waitlist_facility_status_idx');

            $table->foreign('appointment_type_id')->references('id')->on('sch_appointment_types')->restrictOnDelete();
            $table->foreign('resource_id')->references('id')->on('sch_schedulable_resources')->nullOnDelete();
            $table->foreign('offered_slot_id')->references('id')->on('sch_slots')->nullOnDelete();
            $table->foreign('booked_appointment_id')->references('id')->on('sch_appointments')->nullOnDelete();
        });

        Rls::enable('sch_waitlist_entries');
    }

    public function down(): void
    {
        Rls::disable('sch_waitlist_entries');
        Schema::dropIfExists('sch_waitlist_entries');
    }
};
