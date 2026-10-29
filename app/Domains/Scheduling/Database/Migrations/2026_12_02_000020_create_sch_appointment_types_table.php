<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment SCH.1) — appointment types table
 * (22-scheduling-and-appointments-domain.md §7, §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sch_appointment_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable()->index();

            $table->string('code', 64);
            $table->string('name', 255);
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->boolean('is_virtual')->default(false);
            $table->boolean('allow_patient_booking')->default(true);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        Rls::enable('sch_appointment_types');
    }

    public function down(): void
    {
        Rls::disable('sch_appointment_types');
        Schema::dropIfExists('sch_appointment_types');
    }
};
