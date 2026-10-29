<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\EvaluationScores;
use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\Exception\EvaluationScoresIncomplete;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForEvaluation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\EvaluationLifecycle;
use App\Domains\Procurement\Domain\Service\QuotationEvaluationScorer;
use App\Shared\Domain\NotFound;

/**
 * Orchestrates `SubmitEvaluation` (ADR-174 D-C, amended by ADR-175 D-D). Legal
 * only while the caller's evaluation is DRAFT AND the RFQ is CLOSED. Every
 * MANDATORY dimension (per the tenant's `pcr_evaluation_settings`, or all four
 * by default) must be scored; `overall_score` is computed with the tenant
 * weights + snapshotted; the evaluation is locked to SUBMITTED.
 *
 * The RFQ is loaded `FOR UPDATE` — the serialization point against a concurrent
 * `AwardRfq`.
 *
 * `QuotationEvaluationRepository` is the first repository parameter →
 * `QuotationEvaluationPolicy::manage`.
 */
final class SubmitEvaluationHandler
{
    public function __construct(
        private readonly QuotationEvaluationRepository $evaluations,
        private readonly RfqRepository $rfqs,
        private readonly EvaluationLifecycle $lifecycle,
        private readonly QuotationEvaluationScorer $scorer,
        private readonly EvaluationSettingsReader $settings,
    ) {}

    public function __invoke(SubmitEvaluation $command): void
    {
        $evaluation = $this->evaluations->getByIdForUpdate($command->evaluationId);

        if ((string) $evaluation->evaluator_user_id !== $command->actorId) {
            throw NotFound::aggregate(QuotationEvaluation::class, $command->evaluationId);
        }

        $this->lifecycle->assertCanSubmit((string) $evaluation->status);

        $rfq = $this->rfqs->getByIdForUpdate((string) $evaluation->rfq_id);
        if ((string) $rfq->status !== Rfq::STATUS_CLOSED) {
            throw RfqNotOpenForEvaluation::status((string) $rfq->id, (string) $rfq->status);
        }

        $criteria = $this->settings->forTenant();
        $scores = EvaluationScores::fromEvaluation($evaluation);

        foreach ($criteria->mandatoryDimensions() as $dimension) {
            if (! $scores->has($dimension)) {
                throw EvaluationScoresIncomplete::forEvaluation((string) $evaluation->id);
            }
        }

        $evaluation->submit($this->scorer->overall($scores, $criteria));

        $this->evaluations->save($evaluation);
    }
}
