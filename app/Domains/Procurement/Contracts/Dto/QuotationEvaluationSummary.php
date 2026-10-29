<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One evaluator's evaluation of a quotation (ADR-174 D-D; `belowPassThreshold`
 * added by ADR-175 D-E). Returned ONLY through `GetQuotationEvaluations`, gated
 * on `procurement.quotations.view` (§207 / §208 — the "commercial + evaluation"
 * tier) and subject to the §207 independence filter. Scores are `null` on a
 * DRAFT / an optional dimension; `overallScore` is `null` until `SubmitEvaluation`.
 * `belowPassThreshold` is `true` only when the tenant set a `pass_threshold` and
 * this SUBMITTED `overallScore` is under it — it flags, it NEVER blocks an award
 * (§35).
 */
final readonly class QuotationEvaluationSummary
{
    public function __construct(
        public string $evaluationId,
        public string $evaluatorUserId,
        public ?int $commercialScore,
        public ?int $technicalScore,
        public ?int $qualityScore,
        public ?int $deliveryScore,
        public ?int $overallScore,
        public string $status,
        public ?string $submittedAt,
        public ?string $comments,
        public bool $belowPassThreshold = false,
    ) {}
}
