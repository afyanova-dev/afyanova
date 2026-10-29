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
        Schema::create('rpt_lab_tat_daily', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('facility_id')->index();
            $table->date('tat_date')->index();
            $table->string('test_category', 64)->default('GENERAL');
            $table->integer('total_tests_completed')->default(0);
            $table->decimal('avg_tat_minutes', 8, 2)->default(0);
            $table->integer('within_target_count')->default(0);
            $table->integer('breached_target_count')->default(0);
            $table->timestampTz('as_of_time');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'tat_date', 'test_category'], 'rpt_lab_tat_daily_unique');
        });

        Rls::enable('rpt_lab_tat_daily');
    }

    public function down(): void
    {
        Rls::disable('rpt_lab_tat_daily');
        Schema::dropIfExists('rpt_lab_tat_daily');
    }
};
