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
        Schema::create('int_inbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('connector_id')->index();

            $table->string('external_ref', 150)->index();
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable()->index();
            $table->boolean('signature_valid')->default(false);
            $table->jsonb('raw_payload')->nullable();
            $table->string('translated_command_type', 150)->nullable();
            $table->jsonb('translated_payload')->nullable();
            $table->string('dispatch_status', 30)->default('PENDING')->index();
            $table->text('domain_outcome_reason')->nullable();
            $table->string('status', 30)->default('RECEIVED')->index();
            $table->text('dead_letter_reason')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('dispatched_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'connector_id', 'external_ref']);
        });

        Rls::enable('int_inbound_messages');
    }

    public function down(): void
    {
        Rls::disable('int_inbound_messages');
        Schema::dropIfExists('int_inbound_messages');
    }
};
