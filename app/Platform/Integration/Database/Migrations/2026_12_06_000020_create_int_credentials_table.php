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
        Schema::create('int_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('connector_id')->constrained('int_connectors')->cascadeOnDelete();

            $table->string('key_identifier', 100)->index();
            $table->string('secret_ref', 150);
            $table->string('algorithm', 50)->default('RAW_TOKEN');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_rotated_at')->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();

            $table->timestampsTz();
        });

        Rls::enable('int_credentials');
    }

    public function down(): void
    {
        Rls::disable('int_credentials');
        Schema::dropIfExists('int_credentials');
    }
};
