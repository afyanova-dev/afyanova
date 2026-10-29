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
        Schema::create('rpt_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('run_number', 32)->index();
            $table->uuid('report_definition_id')->nullable()->index();
            $table->string('report_definition_code', 64)->index();
            $table->jsonb('parameters')->nullable();
            $table->string('status', 32)->default('REQUESTED')->index();
            $table->uuid('requested_by')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->timestampTz('as_of_time');
            $table->integer('data_freshness_lag_seconds')->default(0);
            $table->jsonb('result_dataset')->nullable();
            $table->integer('row_count')->default(0);
            $table->uuid('document_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        Rls::enable('rpt_report_runs');
    }

    public function down(): void
    {
        Rls::disable('rpt_report_runs');
        Schema::dropIfExists('rpt_report_runs');
    }
};
