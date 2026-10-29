<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel an RFQ (19-procurement-domain.md §31; ADR-173 D-D). Legal from
 * DRAFT | ISSUED | CLOSED (NOT from AWARDED). `reason` is mandatory + ROW-ONLY.
 */
final readonly class CancelRfq implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $rfqId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($rfqId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelRfq ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CancelRfq::$reason must not be blank.');
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
            action: 'procurement.rfq_cancelled',
            actorId: $this->actorId,
            targetType: 'rfq',
            targetId: $this->rfqId,
            reason: trim($this->reason),
        );
    }
}
