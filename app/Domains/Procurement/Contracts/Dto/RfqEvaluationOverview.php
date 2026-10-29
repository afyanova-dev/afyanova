<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * The evaluation picture for an RFQ — one group per SUBMITTED quotation
 * (ADR-174 D-D; `passThreshold` added by ADR-175 D-E). Returned by
 * `GetQuotationEvaluations` on `procurement.quotations.view`; NOT an event
 * payload (evaluation data never leaves through an event — ADR-173 D-M /
 * ADR-174 D-I). `passThreshold` is the tenant's configured pass mark (or `null`)
 * against which each group's evaluations carry `belowPassThreshold`.
 *
 * @param  list<QuotationEvaluationGroup>  $groups
 */
final readonly class RfqEvaluationOverview
{
    /**
     * @param  list<QuotationEvaluationGroup>  $groups
     */
    public function __construct(
        public string $rfqId,
        public array $groups,
        public ?int $passThreshold = null,
    ) {}
}
