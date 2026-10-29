<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * The "Combined Evaluation" (§206) for one SUBMITTED quotation on an RFQ
 * (ADR-174 D-D). `submittedCount` is always the true number of SUBMITTED
 * evaluations; `averageOverall` and the `evaluations` list are filtered per the
 * §207 independence rule — when `viewerGated` is true the caller sees only
 * their own (DRAFT) row and no peer scores.
 *
 * @param  list<QuotationEvaluationSummary>  $evaluations
 */
final readonly class QuotationEvaluationGroup
{
    /**
     * @param  list<QuotationEvaluationSummary>  $evaluations
     */
    public function __construct(
        public string $quotationId,
        public string $supplierId,
        public int $submittedCount,
        public ?int $averageOverall,
        public bool $viewerGated,
        public array $evaluations,
    ) {}
}
