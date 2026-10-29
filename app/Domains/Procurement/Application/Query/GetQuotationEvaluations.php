<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The combined evaluation overview for an RFQ — one group per SUBMITTED
 * quotation, each with its evaluators' scores (ADR-174 D-D). `view` gates on
 * **`procurement.quotations.view`** (§207 / §208 — the same tier as competing
 * prices); the handler adds an ADR-133 refinement at the RFQ's facility and the
 * §207 independence filter (an evaluator mid-evaluation does not see peers'
 * scores for that quotation until they submit).
 */
#[ProtectedQuery(ability: 'view', subjectType: Quotation::class)]
final readonly class GetQuotationEvaluations
{
    public function __construct(public string $rfqId)
    {
        if (! Str::isUuid($rfqId)) {
            throw new InvalidArgumentException('GetQuotationEvaluations::$rfqId must be a UUID (IC §3).');
        }
    }
}
