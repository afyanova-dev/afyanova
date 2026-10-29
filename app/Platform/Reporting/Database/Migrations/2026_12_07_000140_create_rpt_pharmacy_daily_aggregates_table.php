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
        Schema::create('rpt_pharmacy_daily_aggregates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->date('dispense_date')->index();
            $table->integer('total_prescriptions')->default(0);
            $table->integer('total_active')->default(0);
            $table->integer('total_cancelled')->default(0);
            $table->timestampTz('as_of_time');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'dispense_date'], 'rpt_pharmacy_daily_unique');
        });

        Rls::enable('rpt_pharmacy_daily_aggregates');
    }

    public function down(): void
    {
        Rls::disable('rpt_pharmacy_daily_aggregates');
        Schema::dropIfExists('rpt_pharmacy_daily_aggregates');
    }
};
