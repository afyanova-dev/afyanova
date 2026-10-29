<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.1) — schedule exceptions table
 * (22-scheduling-and-appointments-domain.md §40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->uuid('resource_id')->index();
            $table->date('exception_date')->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_available')->default(false);
            $table->string('reason', 255);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('resource_id')->references('id')->on('sch_schedulable_resources')->cascadeOnDelete();
        });

        Rls::enable('sch_schedule_exceptions');
    }

    public function down(): void
    {
        Rls::disable('sch_schedule_exceptions');
        Schema::dropIfExists('sch_schedule_exceptions');
    }
};
