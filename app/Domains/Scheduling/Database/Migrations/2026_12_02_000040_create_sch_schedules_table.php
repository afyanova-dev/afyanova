<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.1) — recurring working schedules table
 * (22-scheduling-and-appointments-domain.md §37, §39).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->uuid('resource_id')->index();
            $table->uuid('appointment_type_id')->nullable()->index();

            $table->unsignedSmallInteger('day_of_week'); // 1 (Monday) .. 7 (Sunday)
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('resource_id')->references('id')->on('sch_schedulable_resources')->cascadeOnDelete();
            $table->foreign('appointment_type_id')->references('id')->on('sch_appointment_types')->nullOnDelete();
        });

        Rls::enable('sch_schedules');
    }

    public function down(): void
    {
        Rls::disable('sch_schedules');
        Schema::dropIfExists('sch_schedules');
    }
};
