<?php

declare(strict_types=1);

namespace App\Shared\Bus\Middleware;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Closure;

/**
 * Audit middleware for auditable commands (IMPLEMENTATION-CONTRACTS.md §5 step 5, §18; AGENTS.md §39).
 */
final class WriteAuditRow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(object $command, Closure $next): mixed
    {
        $result = $next($command);

        if ($command instanceof Auditable) {
            $entry = $command->auditEntry();
            $facilityId = $command instanceof AuditFacilityAware
                ? $command->auditFacilityId()
                : ($entry->facilityId ?? $this->tenantContext->facilityId());

            $this->auditLogger->recordFor(
                tenantId: $this->tenantContext->tenantId(),
                action: $entry->action,
                actorId: $entry->actorId,
                facilityId: $facilityId,
                reason: $entry->reason,
                metadata: $entry->metadata,
                targetType: $entry->targetType,
                targetId: $entry->targetId,
            );
        }

        return $result;
    }
}
