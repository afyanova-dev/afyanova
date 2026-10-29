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
        Schema::create('notif_deduplication_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('dedupe_key');
            $table->uuid('request_id')->index();
            $table->timestampTz('expires_at')->index();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'dedupe_key']);
        });

        Rls::enable('notif_deduplication_keys');
    }

    public function down(): void
    {
        Rls::disable('notif_deduplication_keys');
        Schema::dropIfExists('notif_deduplication_keys');
    }
};
