<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lock the acting user's own DRAFT evaluation to SUBMITTED
 * (19-procurement-domain.md §36, §207; ADR-174 D-C). All four dimension scores
 * must be present (`EvaluationScoresIncomplete`); `overall_score` is computed +
 * snapshotted by `QuotationEvaluationScorer`. After this the evaluator sees
 * peers' SUBMITTED scores for that quotation (§207 — the independence filter).
 * A SUBMITTED evaluation is final in Proc.2b.
 */
final readonly class SubmitEvaluation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $evaluationId,
        public string $actorId,
    ) {
        if (! Str::isUuid($evaluationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SubmitEvaluation ids must be UUIDs (IC §3).');
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
            action: 'procurement.evaluation_submitted',
            actorId: $this->actorId,
            targetType: 'quotation_evaluation',
            targetId: $this->evaluationId,
        );
    }
}
