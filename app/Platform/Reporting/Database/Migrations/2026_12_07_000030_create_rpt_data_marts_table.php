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
        Schema::create('rpt_data_marts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('category', 32)->index();
            $table->text('description')->nullable();
            $table->jsonb('source_projections')->nullable();
            $table->jsonb('schema_definition');
            $table->boolean('is_curated')->default(true);
            $table->string('status', 32)->default('ACTIVE')->index();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        Rls::enable('rpt_data_marts');
    }

    public function down(): void
    {
        Rls::disable('rpt_data_marts');
        Schema::dropIfExists('rpt_data_marts');
    }
};
