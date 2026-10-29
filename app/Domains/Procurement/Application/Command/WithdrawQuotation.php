<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Withdraw a quotation (19-procurement-domain.md §32; ADR-173 D-D). Legal only
 * while the quotation is RECEIVED AND the RFQ is ISSUED. `reason` is mandatory
 * + ROW-ONLY (→ the audit row only).
 */
final readonly class WithdrawQuotation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $quotationId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($quotationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('WithdrawQuotation ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('WithdrawQuotation::$reason must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->quotationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.quotation_withdrawn',
            actorId: $this->actorId,
            targetType: 'quotation',
            targetId: $this->quotationId,
            reason: trim($this->reason),
        );
    }
}
