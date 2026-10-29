<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `EvaluateQuotation` was dispatched for a (quotation, evaluator) pair that
 * already has an evaluation row (19-procurement-domain.md §36, §206; ADR-174
 * D-C — one per (quotation, evaluator)). The evaluator revises the existing
 * DRAFT instead. Maps to HTTP 409.
 */
final class EvaluationAlreadyExists extends DomainException
{
    public static function forQuotationAndEvaluator(string $quotationId, string $evaluatorUserId): self
    {
        return new self(sprintf(
            'An evaluation already exists for quotation [%s] by evaluator [%s] — revise it.',
            $quotationId,
            $evaluatorUserId,
        ));
    }

    public function errorCode(): string
    {
        return 'evaluation_already_exists';
    }
}
