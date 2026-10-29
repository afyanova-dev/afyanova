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
        Schema::create('rpt_report_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('category', 32)->index();
            $table->text('description')->nullable();
            $table->string('data_mart_code', 64)->index();
            $table->jsonb('query_template');
            $table->string('schedule_cron', 64)->nullable();
            $table->jsonb('distribution_list')->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        Rls::enable('rpt_report_definitions');
    }

    public function down(): void
    {
        Rls::disable('rpt_report_definitions');
        Schema::dropIfExists('rpt_report_definitions');
    }
};
