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
        Schema::create('rpt_bed_occupancy_daily', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->uuid('ward_id')->nullable()->index();
            $table->date('occupancy_date')->index();
            $table->integer('total_beds')->default(0);
            $table->integer('occupied_beds')->default(0);
            $table->decimal('occupancy_rate_percent', 5, 2)->default(0);
            $table->integer('admissions_count')->default(0);
            $table->integer('discharges_count')->default(0);
            $table->timestampTz('as_of_time');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        DB::statement(
            'create unique index rpt_bed_occupancy_daily_unique '.
            'on rpt_bed_occupancy_daily (tenant_id, facility_id, ward_id, occupancy_date) nulls not distinct'
        );

        Rls::enable('rpt_bed_occupancy_daily');
    }

    public function down(): void
    {
        Rls::disable('rpt_bed_occupancy_daily');
        Schema::dropIfExists('rpt_bed_occupancy_daily');
    }
};
