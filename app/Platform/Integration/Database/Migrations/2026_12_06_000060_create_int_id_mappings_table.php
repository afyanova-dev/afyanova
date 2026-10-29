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
        Schema::create('int_id_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('connector_id')->index();

            $table->string('external_id', 150)->index();
            $table->string('internal_domain', 50)->index();
            $table->uuid('internal_id')->index();
            $table->string('mapping_source', 30)->default('INBOUND_OBSERVED');
            $table->string('verification_status', 30)->default('UNVERIFIED');
            $table->decimal('confidence', 3, 2)->default(1.00);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'connector_id', 'external_id'], 'int_id_mappings_external_unique');
            $table->unique(['tenant_id', 'connector_id', 'internal_domain', 'internal_id'], 'int_id_mappings_internal_unique');
        });

        Rls::enable('int_id_mappings');
    }

    public function down(): void
    {
        Rls::disable('int_id_mappings');
        Schema::dropIfExists('int_id_mappings');
    }
};
