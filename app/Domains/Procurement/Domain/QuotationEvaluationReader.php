<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\RfqEvaluationOverview;

/**
 * Read-side port for quotation evaluations (IC §6.2 / §6.3; ADR-174 D-D).
 * Exposes competing supplier scoring — the query handler gates on
 * `procurement.quotations.view` (§207 / §208) and the reader applies the §207
 * independence filter against the acting user.
 */
interface QuotationEvaluationReader
{
    /**
     * The combined evaluation overview for an RFQ, filtered for `$viewerUserId`
     * per §207 (an evaluator with an unsubmitted DRAFT for a quotation does not
     * see peers' scores for it until they submit, or the RFQ is AWARDED).
     */
    public function overviewForRfq(string $rfqId, string $viewerUserId): RfqEvaluationOverview;
}
