<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record the acting user's structured evaluation of a supplier quotation
 * (19-procurement-domain.md §36, §206; ADR-174 D-C). Legal only while the RFQ
 * is CLOSED and the quotation is SUBMITTED. `$evaluationId` is client-minted
 * (UUIDv7); one evaluation per (quotation, evaluator) — a second dispatch is
 * `EvaluationAlreadyExists` (revise instead). Each dimension score, when
 * supplied, is 0..100; scores are optional at this stage (a working DRAFT) and
 * become mandatory at `SubmitEvaluation`.
 */
final readonly class EvaluateQuotation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $evaluationId,
        public string $actorId,
        public string $quotationId,
        public ?int $commercialScore = null,
        public ?int $technicalScore = null,
        public ?int $qualityScore = null,
        public ?int $deliveryScore = null,
        public ?string $comments = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'evaluationId' => $evaluationId, 'actorId' => $actorId, 'quotationId' => $quotationId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("EvaluateQuotation::\${$name} must be a UUID (IC §3).");
            }
        }

        foreach ([
            'commercialScore' => $commercialScore, 'technicalScore' => $technicalScore,
            'qualityScore' => $qualityScore, 'deliveryScore' => $deliveryScore,
        ] as $name => $score) {
            if ($score !== null && ($score < 0 || $score > 100)) {
                throw new InvalidArgumentException("EvaluateQuotation::\${$name} must be 0..100 (§36).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.quotation_evaluated',
            actorId: $this->actorId,
            targetType: 'quotation_evaluation',
            targetId: $this->evaluationId,
            metadata: [
                'quotation_id' => $this->quotationId,
                'dimensions_scored' => count(array_filter(
                    [$this->commercialScore, $this->technicalScore, $this->qualityScore, $this->deliveryScore],
                    static fn (?int $s): bool => $s !== null,
                )),
            ],
        );
    }
}
