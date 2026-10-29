<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shared_outbox_events` — IMPLEMENTATION-CONTRACTS.md §7.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id');
            $table->string('event_name')->index();
            $table->string('event_class');
            $table->jsonb('payload');
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->timestampTz('created_at')->index();
            $table->timestampTz('available_at')->index();
            $table->timestampTz('published_at')->nullable()->index();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['published_at', 'available_at']);
        });

        Rls::enable('shared_outbox_events');
    }

    public function down(): void
    {
        Rls::disable('shared_outbox_events');
        Schema::dropIfExists('shared_outbox_events');
    }
};
