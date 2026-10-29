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
        Schema::create('rpt_metric_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('owning_domain', 64)->index();
            $table->string('category', 32)->index();
            $table->text('description')->nullable();
            $table->text('calculation_logic')->nullable();
            $table->string('unit_of_measure', 32)->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->uuid('signed_off_by')->nullable();
            $table->timestampTz('signed_off_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'code', 'version']);
        });

        Rls::enable('rpt_metric_definitions');
    }

    public function down(): void
    {
        Rls::disable('rpt_metric_definitions');
        Schema::dropIfExists('rpt_metric_definitions');
    }
};
