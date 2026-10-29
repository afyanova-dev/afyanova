<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Opt-in for commands whose audit record must use the affected facility rather
 * than the request's active facility. Returning null denotes an organization-
 * or tenant-level action.
 */
interface AuditFacilityAware
{
    public function auditFacilityId(): ?string;
}
