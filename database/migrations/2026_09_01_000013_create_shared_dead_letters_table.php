<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shared_dead_letters` — IMPLEMENTATION-CONTRACTS.md §14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_dead_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id');
            $table->string('source_type');
            $table->string('source_id');
            $table->jsonb('payload');
            $table->text('error');
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'created_at']);
        });

        Rls::enable('shared_dead_letters');
        Rls::enableAppendOnly('shared_dead_letters');
    }

    public function down(): void
    {
        Rls::disable('shared_dead_letters');
        Schema::dropIfExists('shared_dead_letters');
    }
};
