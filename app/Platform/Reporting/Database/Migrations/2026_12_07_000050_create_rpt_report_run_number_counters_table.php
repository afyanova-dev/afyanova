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
        Schema::create('rpt_report_run_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->string('period_key', 16);
            $table->unsignedInteger('current_val')->default(0);
            $table->unsignedBigInteger('version')->default(0);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'period_key']);
        });

        Rls::enable('rpt_report_run_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rpt_report_run_number_counters');
        Schema::dropIfExists('rpt_report_run_number_counters');
    }
};
