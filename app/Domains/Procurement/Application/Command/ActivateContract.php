<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Activate a DRAFT purchase contract (19-procurement-domain.md §40; ADR-176
 * D-C). DRAFT → ACTIVE. `actorId ≠ contract.created_by_user_id` (§24 / §228 —
 * activation is a commercial commitment).
 */
final readonly class ActivateContract implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $contractId,
        public string $actorId,
    ) {
        if (! Str::isUuid($contractId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ActivateContract ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->contractId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.contract_activated',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
        );
    }
}
