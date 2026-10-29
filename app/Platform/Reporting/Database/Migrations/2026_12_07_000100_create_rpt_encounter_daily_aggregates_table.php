<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpt_encounter_daily_aggregates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->date('aggregate_date')->index();
            $table->string('encounter_type', 32)->default('OUTPATIENT');
            $table->integer('total_encounters')->default(0);
            $table->integer('total_admissions')->default(0);
            $table->integer('total_discharges')->default(0);
            $table->integer('emergency_count')->default(0);
            $table->decimal('avg_duration_minutes', 8, 2)->default(0);
            $table->timestampTz('as_of_time');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'aggregate_date', 'encounter_type'], 'rpt_encounter_daily_unique');
        });

        Rls::enable('rpt_encounter_daily_aggregates');
    }

    public function down(): void
    {
        Rls::disable('rpt_encounter_daily_aggregates');
        Schema::dropIfExists('rpt_encounter_daily_aggregates');
    }
};
