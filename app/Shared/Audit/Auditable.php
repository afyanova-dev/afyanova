<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Commands that must be audited implement this interface (IMPLEMENTATION-CONTRACTS.md §18, §5).
 * Declares exactly one method: auditEntry(): AuditEntry.
 */
interface Auditable
{
    public function auditEntry(): AuditEntry;
}
