<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use RuntimeException;

/**
 * Request-scoped security context (IMPLEMENTATION-CONTRACTS.md §11.1,
 * docs/00-foundation/05-tenant-isolation.md §7–§9).
 *
 * Bound as a singleton per request. Populated exactly once — for HTTP by
 * {@see ResolveTenantContext} from the authenticated principal; for non-HTTP
 * entry points (seeders, `artisan` console commands, queued jobs) by
 * {@see TenantContextScope::run}, which rebinds a fresh instance for the unit
 * of work and restores the previous one after. Never populated from a header,
 * body, query string, or route parameter. Immutable after establish(): there
 * is no `setTenantId()` a controller, handler, or model can reach.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?string $facilityId = null;

    private bool $general = false;

    private bool $resolved = false;

    /**
     * Called once per request by the middleware. A second call is a bug.
     */
    public function establish(string $tenantId, ?string $facilityId, bool $general = false): void
    {
        if ($this->resolved) {
            throw new RuntimeException('Tenant context is already established for this request.');
        }
        if ($general && $facilityId !== null) {
            throw new RuntimeException('General context cannot include an active facility.');
        }

        $this->tenantId = $tenantId;
        $this->facilityId = $facilityId;
        $this->general = $general;
        $this->resolved = true;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): string
    {
        return $this->tenantId
            ?? throw new RuntimeException('No tenant context — this route must run behind ResolveTenantContext.');
    }

    public function facilityId(): ?string
    {
        return $this->facilityId;
    }

    public function isGeneral(): bool
    {
        return $this->general;
    }

    public function isResolved(): bool
    {
        return $this->general || $this->facilityId !== null;
    }

    public function requireFacilityId(): string
    {
        return $this->facilityId
            ?? throw new RuntimeException('This operation requires an active facility context.');
    }
}
