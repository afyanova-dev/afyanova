<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shared_idempotency_keys` — IMPLEMENTATION-CONTRACTS.md §5, §14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id');
            $table->string('scope');
            $table->string('key');
            $table->string('status')->default('in_progress');
            $table->text('result')->nullable();   // serialized …Result DTO (opaque; §5 "return its stored result")
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'scope', 'key']);
        });

        Rls::enable('shared_idempotency_keys');
    }

    public function down(): void
    {
        Rls::disable('shared_idempotency_keys');
        Schema::dropIfExists('shared_idempotency_keys');
    }
};
