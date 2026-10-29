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
        Schema::create('rpt_statutory_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('return_number', 32)->index();
            $table->string('framework', 32)->index();
            $table->string('period_type', 20)->default('MONTHLY');
            $table->string('period_key', 20)->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->string('reporting_unit_code', 64)->index();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->uuid('supersedes_return_id')->nullable()->index();
            $table->uuid('superseded_by_return_id')->nullable()->index();
            $table->jsonb('submitted_dataset')->nullable();
            $table->jsonb('submission_receipt')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->string('reference_config_version', 32)->default('v1.0');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        Rls::enable('rpt_statutory_returns');
    }

    public function down(): void
    {
        Rls::disable('rpt_statutory_returns');
        Schema::dropIfExists('rpt_statutory_returns');
    }
};
