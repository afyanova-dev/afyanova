<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Patch the acting user's own DRAFT quotation evaluation before submission
 * (19-procurement-domain.md §36; ADR-174 D-C). Legal only while the evaluation
 * is DRAFT AND the RFQ is CLOSED. A null score leaves that dimension unchanged;
 * `comments === null` leaves the note, `''` clears it. At least one field must
 * change.
 */
final readonly class ReviseEvaluation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $evaluationId,
        public string $actorId,
        public ?int $commercialScore = null,
        public ?int $technicalScore = null,
        public ?int $qualityScore = null,
        public ?int $deliveryScore = null,
        public ?string $comments = null,
    ) {
        if (! Str::isUuid($evaluationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReviseEvaluation ids must be UUIDs (IC §3).');
        }

        foreach ([
            'commercialScore' => $commercialScore, 'technicalScore' => $technicalScore,
            'qualityScore' => $qualityScore, 'deliveryScore' => $deliveryScore,
        ] as $name => $score) {
            if ($score !== null && ($score < 0 || $score > 100)) {
                throw new InvalidArgumentException("ReviseEvaluation::\${$name} must be 0..100 (§36).");
            }
        }

        if ($commercialScore === null && $technicalScore === null && $qualityScore === null
            && $deliveryScore === null && $comments === null) {
            throw new InvalidArgumentException('ReviseEvaluation requires at least one field to change.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->evaluationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.evaluation_revised',
            actorId: $this->actorId,
            targetType: 'quotation_evaluation',
            targetId: $this->evaluationId,
            metadata: [
                'dimensions_scored' => count(array_filter(
                    [$this->commercialScore, $this->technicalScore, $this->qualityScore, $this->deliveryScore],
                    static fn (?int $s): bool => $s !== null,
                )),
            ],
        );
    }
}
