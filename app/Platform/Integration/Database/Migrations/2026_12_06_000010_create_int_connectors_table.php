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
        Schema::create('int_connectors', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('key', 64);
            $table->string('family', 30)->index();
            $table->string('name', 150);
            $table->string('direction', 20)->default('BIDIRECTIONAL');
            $table->jsonb('endpoint_config')->nullable();
            $table->string('auth_type', 30)->default('NONE');
            $table->string('secret_ref', 150)->nullable();
            $table->integer('rate_limit_per_minute')->nullable();
            $table->string('payload_sensitivity', 20)->default('STANDARD');
            $table->integer('retention_days')->default(90);
            $table->string('status', 20)->default('DISABLED')->index();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });

        Rls::enable('int_connectors');
    }

    public function down(): void
    {
        Rls::disable('int_connectors');
        Schema::dropIfExists('int_connectors');
    }
};
