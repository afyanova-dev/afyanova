<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Award a CLOSED RFQ (19-procurement-domain.md §37, §38, §39; ADR-173 D-D).
 *
 * ONE-SHOT: the complete allocation is supplied in one call. Each entry is
 * `{rfqLineId, quotationId, awardedQty, reason}`. Split award (§38) = ≥ 2
 * entries for one `rfqLineId` with different `quotationId`; partial (§39) = Σ
 * `awardedQty` per line < the RFQ line quantity (the shortfall is surfaced in
 * `GetRfq`, NOT auto-re-sourced). `decided_by ≠ rfq.created_by` (§24 / §228 /
 * Inv 29). Proc.2a does NOT rank / score / auto-select — the human decides and
 * states why (§35). AWARDED is terminal in Proc.2a.
 *
 * @param  array<int, array<string, mixed>>  $allocation
 */
final readonly class AwardRfq implements Auditable, AuthorizesAgainst
{
    /** @var list<array{rfqLineId: string, quotationId: string, awardedQty: string, reason: string}> */
    public array $allocation;

    /**
     * @param  array<int, array<string, mixed>>  $allocation
     */
    public function __construct(
        public string $rfqId,
        public string $actorId,
        array $allocation,
    ) {
        if (! Str::isUuid($rfqId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AwardRfq ids must be UUIDs (IC §3).');
        }

        $allocation = array_values($allocation);
        if ($allocation === []) {
            throw RfqAwardInvalid::empty();
        }

        $normalised = [];
        foreach ($allocation as $i => $entry) {
            $rfqLineId = $entry['rfqLineId'] ?? null;
            $quotationId = $entry['quotationId'] ?? null;
            $awardedQty = $entry['awardedQty'] ?? null;
            $reason = $entry['reason'] ?? null;

            if (! is_string($rfqLineId) || ! Str::isUuid($rfqLineId) || ! is_string($quotationId) || ! Str::isUuid($quotationId)) {
                throw new InvalidArgumentException(sprintf('AwardRfq allocation entry %d needs `rfqLineId` + `quotationId` UUIDs (IC §3).', $i + 1));
            }
            if ($awardedQty === null || ! is_numeric((string) $awardedQty) || (float) $awardedQty <= 0) {
                throw new InvalidArgumentException(sprintf('AwardRfq allocation entry %d needs a positive `awardedQty`.', $i + 1));
            }
            if (! is_string($reason) || trim($reason) === '') {
                throw new InvalidArgumentException(sprintf('AwardRfq allocation entry %d needs a non-blank `reason` (§37 — ROW-ONLY).', $i + 1));
            }

            $normalised[] = [
                'rfqLineId' => $rfqLineId,
                'quotationId' => $quotationId,
                'awardedQty' => (string) $awardedQty,
                'reason' => trim($reason),
            ];
        }

        $this->allocation = $normalised;
    }

    public function policyAbility(): string
    {
        return 'award';
    }

    public function subjectId(): string
    {
        return $this->rfqId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.rfq_awarded',
            actorId: $this->actorId,
            targetType: 'rfq',
            targetId: $this->rfqId,
            metadata: ['award_entry_count' => count($this->allocation)],
        );
    }
}
