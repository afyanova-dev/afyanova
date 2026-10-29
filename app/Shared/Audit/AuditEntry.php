<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * One audit record (IMPLEMENTATION-CONTRACTS.md §18). Written to
 * `shared_audit_log`. PHASE 4 adds the `Auditable` command interface + the
 * command-bus path that writes these inside the handler transaction; PHASE 2
 * writes them directly for auth events and privileged operations.
 *
 * Never carries PHI beyond identifiers and coded values (`AGENTS.md §38`).
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public ?string $tenantId = null,
        public ?string $facilityId = null,
        public ?string $actorId = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $reason = null,
        public ?string $causationId = null,
        public array $metadata = [],
    ) {}
}
