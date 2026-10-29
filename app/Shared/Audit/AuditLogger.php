<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\ResolveTenantContext;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Direct writer for `shared_audit_log` (IMPLEMENTATION-CONTRACTS.md §18).
 *
 * PHASE 2 scope: privileged-operation and authentication-event audit. PHASE 4
 * layers the `Auditable` + command-bus lifecycle on the SAME table — no rework.
 *
 * The table is append-only at the database (`Rls::enableAppendOnly`).
 */
final class AuditLogger
{
    public function __construct(private readonly Container $container) {}

    /**
     * The tenant context is resolved lazily — an `AuditLogger` may be
     * constructed before {@see ResolveTenantContext} or {@see TenantContextScope}
     * has (re)bound the request/command context (e.g. a console command that
     * type-hints it on `handle()`).
     */
    private function context(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    /**
     * Record with the current request's tenant/facility context filled in.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?string $actorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $context = $this->context();

        $this->write(new AuditEntry(
            action: $action,
            tenantId: $context->hasTenant() ? $context->tenantId() : null,
            facilityId: $context->hasTenant() ? $context->facilityId() : null,
            actorId: $actorId,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            metadata: $metadata,
        ));
    }

    /**
     * Record with an EXPLICIT tenant — for the pre-context window (an auth event
     * fires before {@see ResolveTenantContext} establishes
     * the context). The caller must already be inside `Tenancy::run($tenantId)`
     * so the append-only RLS WITH CHECK passes.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordFor(
        string $tenantId,
        string $action,
        ?string $actorId = null,
        ?string $facilityId = null,
        ?string $reason = null,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
    ): void {
        $this->write(new AuditEntry(
            action: $action,
            tenantId: $tenantId,
            facilityId: $facilityId,
            actorId: $actorId,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            metadata: $metadata,
        ));
    }

    /**
     * Record a platform / system operation — no tenant, no facility, no actor
     * (`SystemContext::run`). `tenant_id` is null; RLS therefore hides the row
     * from every tenant (`docs 05 §51`).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function system(string $action, string $reason, array $metadata = []): void
    {
        $this->write(new AuditEntry(action: $action, reason: $reason, metadata: $metadata));
    }

    /**
     * Record an authenticated platform-level action outside tenant RBAC.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordPlatform(
        string $action,
        string $actorId,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->write(new AuditEntry(
            action: $action,
            tenantId: null,
            facilityId: null,
            actorId: $actorId,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            metadata: $metadata,
        ));
    }

    private function write(AuditEntry $entry): void
    {
        DB::table('shared_audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $entry->tenantId,
            'facility_id' => $entry->facilityId,
            'actor_id' => $entry->actorId,
            'action' => $entry->action,
            'target_type' => $entry->targetType,
            'target_id' => $entry->targetId,
            'reason' => $entry->reason,
            'correlation_id' => CorrelationId::get(),
            'causation_id' => $entry->causationId ?? Causation::get(),
            'metadata' => json_encode($entry->metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
