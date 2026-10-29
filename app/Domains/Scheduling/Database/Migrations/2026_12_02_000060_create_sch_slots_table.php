<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.2) — bookable slots table
 * (22-scheduling-and-appointments-domain.md §42–§45).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->uuid('resource_id')->index();
            $table->uuid('appointment_type_id')->nullable()->index();

            $table->date('slot_date')->index();
            $table->timestampTz('start_time')->index();
            $table->timestampTz('end_time');

            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('booked_count')->default(0);
            $table->string('status', 32)->default('OPEN'); // OPEN, HELD, BOOKED, BLOCKED

            $table->timestampTz('held_until')->nullable();
            $table->string('held_by', 255)->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'resource_id', 'start_time'], 'sch_slots_resource_window_unique');
            $table->foreign('resource_id')->references('id')->on('sch_schedulable_resources')->cascadeOnDelete();
            $table->foreign('appointment_type_id')->references('id')->on('sch_appointment_types')->nullOnDelete();
        });

        Rls::enable('sch_slots');
    }

    public function down(): void
    {
        Rls::disable('sch_slots');
        Schema::dropIfExists('sch_slots');
    }
};
