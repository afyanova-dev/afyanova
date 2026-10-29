<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shared_audit_log` — IMPLEMENTATION-CONTRACTS.md §18.
 *
 * Tenant-isolated and append-only at the database. Also holds platform / system
 * rows (`tenant_id NULL`) written by `SystemContext::run` — invisible to every
 * tenant (docs/00-foundation/05-tenant-isolation.md §51). See
 * `Rls::enableForAuditLog`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('action')->index();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->index();

            $table->index(['tenant_id', 'created_at']);
        });

        Rls::enableForAuditLog('shared_audit_log');
    }

    public function down(): void
    {
        Rls::disable('shared_audit_log');
        Schema::dropIfExists('shared_audit_log');
    }
};
