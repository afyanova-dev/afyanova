<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Flag an RFQ so that its award is HARD-gated on a complete quotation-evaluation
 * set (19-procurement-domain.md §23, §37; ADR-175 D-C). Legal while the RFQ is
 * ISSUED | CLOSED (not DRAFT — nothing to evaluate; not AWARDED — too late).
 * Idempotent — flagging an already-flagged RFQ is a no-op.
 *
 * `procurement.rfqs.manage` (the existing key, per-subject on the RFQ).
 */
final readonly class RequireRfqEvaluation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $rfqId,
        public string $actorId,
    ) {
        if (! Str::isUuid($rfqId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RequireRfqEvaluation ids must be UUIDs (IC §3).');
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
            action: 'procurement.rfq_evaluation_required',
            actorId: $this->actorId,
            targetType: 'rfq',
            targetId: $this->rfqId,
        );
    }
}
