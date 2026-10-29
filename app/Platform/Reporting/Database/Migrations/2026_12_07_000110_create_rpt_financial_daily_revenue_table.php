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
        Schema::create('rpt_financial_daily_revenue', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->date('revenue_date')->index();
            $table->string('payment_method', 32)->default('CASH');
            $table->decimal('total_billed', 15, 2)->default(0);
            $table->decimal('total_collected', 15, 2)->default(0);
            $table->decimal('total_insurance_claimed', 15, 2)->default(0);
            $table->decimal('total_adjustments', 15, 2)->default(0);
            $table->timestampTz('as_of_time');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'revenue_date', 'payment_method'], 'rpt_fin_daily_unique');
        });

        Rls::enable('rpt_financial_daily_revenue');
    }

    public function down(): void
    {
        Rls::disable('rpt_financial_daily_revenue');
        Schema::dropIfExists('rpt_financial_daily_revenue');
    }
};
