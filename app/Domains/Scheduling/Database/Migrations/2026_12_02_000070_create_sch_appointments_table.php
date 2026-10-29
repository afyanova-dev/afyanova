<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.3) — appointments table
 * (22-scheduling-and-appointments-domain.md §46–§60, ADR-207).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('appointment_number', 32);
            $table->uuid('slot_id')->nullable()->index();
            $table->uuid('patient_id')->index(); // External reference to Patient domain (no cross-domain FK, ADR-093)
            $table->uuid('resource_id')->index();
            $table->uuid('appointment_type_id')->index();

            $table->timestampTz('scheduled_start')->index();
            $table->timestampTz('scheduled_end');

            $table->string('status', 32)->default('BOOKED')->index(); // REQUESTED, BOOKED, CONFIRMED, ARRIVED, IN_SERVICE, COMPLETED, CANCELLED, RESCHEDULED, NO_SHOW
            $table->string('reason', 500)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'appointment_number'], 'sch_appointments_tenant_number_unique');
            $table->index(['tenant_id', 'resource_id', 'scheduled_start'], 'sch_appointments_resource_date_idx');

            $table->foreign('slot_id')->references('id')->on('sch_slots')->nullOnDelete();
            $table->foreign('resource_id')->references('id')->on('sch_schedulable_resources')->restrictOnDelete();
            $table->foreign('appointment_type_id')->references('id')->on('sch_appointment_types')->restrictOnDelete();
        });

        Rls::enable('sch_appointments');
    }

    public function down(): void
    {
        Rls::disable('sch_appointments');
        Schema::dropIfExists('sch_appointments');
    }
};
