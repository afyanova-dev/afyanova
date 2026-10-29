<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close an ISSUED RFQ (19-procurement-domain.md §31; ADR-173 D-D). No further
 * quotations are accepted; every RECEIVED quotation is locked to SUBMITTED.
 */
final readonly class CloseRfq implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $rfqId,
        public string $actorId,
    ) {
        if (! Str::isUuid($rfqId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CloseRfq ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->rfqId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.rfq_closed',
            actorId: $this->actorId,
            targetType: 'rfq',
            targetId: $this->rfqId,
        );
    }
}
