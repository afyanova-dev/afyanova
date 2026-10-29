<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalEvaluationTransition;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;

/**
 * Quotation-evaluation lifecycle rules (19-procurement-domain.md §36, §206;
 * ADR-174 D-B / D-J). Pure domain logic, no I/O.
 *
 *   DRAFT -> SUBMITTED   (SubmitEvaluation — all four dimension scores required)
 *   DRAFT is revisable (ReviseEvaluation); SUBMITTED is final in Proc.2b.
 *
 * The "RFQ must be CLOSED" gate is enforced in the command handlers against
 * `Rfq::STATUS_CLOSED` — Proc.2b does NOT touch the closed `RfqLifecycle` /
 * `QuotationLifecycle`.
 */
final class EvaluationLifecycle
{
    public function assertCanRevise(string $status): void
    {
        if ($status !== QuotationEvaluation::STATUS_DRAFT) {
            throw IllegalEvaluationTransition::fromStatus('ReviseEvaluation', $status);
        }
    }

    public function assertCanSubmit(string $status): void
    {
        if ($status !== QuotationEvaluation::STATUS_DRAFT) {
            throw IllegalEvaluationTransition::fromStatus('SubmitEvaluation', $status);
        }
    }
}
