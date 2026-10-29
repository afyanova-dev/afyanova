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
        Schema::create('int_webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('connector_id')->constrained('int_connectors')->cascadeOnDelete();

            $table->string('path_slug', 100);
            $table->string('secret_ref', 150)->nullable();
            $table->jsonb('allowed_ips')->nullable();
            $table->boolean('active')->default(true);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'path_slug']);
        });

        Rls::enable('int_webhook_endpoints');
    }

    public function down(): void
    {
        Rls::disable('int_webhook_endpoints');
        Schema::dropIfExists('int_webhook_endpoints');
    }
};
