<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\RfqNotOpenForEvaluation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\EvaluationLifecycle;
use App\Shared\Domain\NotFound;

/**
 * Orchestrates `ReviseEvaluation` (ADR-174 D-C). Legal only while the caller's
 * evaluation is DRAFT AND the RFQ is CLOSED. Patches the whitelisted fields
 * (the four scores + `comments`) on the aggregate. One bus transaction; no event.
 *
 * `QuotationEvaluationRepository` is the first repository parameter →
 * `QuotationEvaluationPolicy::manage` at the evaluation's facility.
 */
final class ReviseEvaluationHandler
{
    public function __construct(
        private readonly QuotationEvaluationRepository $evaluations,
        private readonly RfqRepository $rfqs,
        private readonly EvaluationLifecycle $lifecycle,
    ) {}

    public function __invoke(ReviseEvaluation $command): void
    {
        $evaluation = $this->evaluations->getByIdForUpdate($command->evaluationId);

        if ((string) $evaluation->evaluator_user_id !== $command->actorId) {
            throw NotFound::aggregate(QuotationEvaluation::class, $command->evaluationId);
        }

        $this->lifecycle->assertCanRevise((string) $evaluation->status);

        $rfq = $this->rfqs->getById((string) $evaluation->rfq_id);
        if ((string) $rfq->status !== Rfq::STATUS_CLOSED) {
            throw RfqNotOpenForEvaluation::status((string) $rfq->id, (string) $rfq->status);
        }

        $evaluation->revise(
            $command->commercialScore,
            $command->technicalScore,
            $command->qualityScore,
            $command->deliveryScore,
            $command->comments,
        );

        $this->evaluations->save($evaluation);
    }
}
