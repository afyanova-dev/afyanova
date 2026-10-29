<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SubmitEvaluation` was dispatched while one or more of the four §36 dimension
 * scores (Commercial / Technical / Quality / Delivery) is still missing
 * (19-procurement-domain.md §36; ADR-174 D-C). Maps to HTTP 409.
 */
final class EvaluationScoresIncomplete extends DomainException
{
    public static function forEvaluation(string $evaluationId): self
    {
        return new self(sprintf(
            'Evaluation [%s] cannot be submitted — all four dimension scores (Commercial / Technical / Quality / Delivery) are required.',
            $evaluationId,
        ));
    }

    public function errorCode(): string
    {
        return 'evaluation_scores_incomplete';
    }
}
