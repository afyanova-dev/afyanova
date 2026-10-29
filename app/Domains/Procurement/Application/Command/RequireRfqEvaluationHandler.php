<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\IllegalRfqTransition;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\RfqRepository;

/**
 * Orchestrates `RequireRfqEvaluation` (ADR-175 D-C). Load the RFQ `FOR UPDATE`
 * → it must be ISSUED | CLOSED → set `requires_evaluation = true` → save. One
 * bus transaction; records NO event.
 *
 * `RfqRepository` is the first (only) repository parameter → `RfqPolicy::manage`
 * (`procurement.rfqs.manage`).
 */
final class RequireRfqEvaluationHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
    ) {}

    public function __invoke(RequireRfqEvaluation $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        if (! in_array((string) $rfq->status, [Rfq::STATUS_ISSUED, Rfq::STATUS_CLOSED], true)) {
            throw IllegalRfqTransition::fromStatus('RequireRfqEvaluation', (string) $rfq->status);
        }

        $rfq->requireEvaluation();

        $this->rfqs->save($rfq);
    }
}
