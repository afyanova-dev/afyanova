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
        Schema::create('int_outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('connector_id')->index();

            $table->string('triggered_by', 150)->index();
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable()->index();
            $table->string('recipient_ref', 150)->nullable();
            $table->jsonb('payload');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->string('provider_ref', 150)->nullable()->index();
            $table->jsonb('last_response')->nullable();
            $table->string('status', 30)->default('QUEUED')->index();
            $table->text('dead_letter_reason')->nullable();

            $table->timestampsTz();
        });

        Rls::enable('int_outbound_messages');
    }

    public function down(): void
    {
        Rls::disable('int_outbound_messages');
        Schema::dropIfExists('int_outbound_messages');
    }
};
