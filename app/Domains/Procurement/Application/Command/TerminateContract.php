<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Terminate a purchase contract (19-procurement-domain.md §40; ADR-176 D-C).
 * DRAFT | ACTIVE → TERMINATED. `reason` is mandatory + ROW-ONLY. Historical POs
 * keep their `contract_id` (Inv 31); a terminated contract can no longer price
 * a new PO (`Contract::isUsable()` → false).
 */
final readonly class TerminateContract implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $contractId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($contractId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('TerminateContract ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('TerminateContract::$reason must not be blank.');
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
            action: 'procurement.contract_terminated',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
            reason: trim($this->reason),
        );
    }
}
