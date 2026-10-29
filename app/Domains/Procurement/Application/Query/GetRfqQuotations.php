<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every quotation on an RFQ — full quotes WITH competing prices + lines +
 * revision history (ADR-173 D-F). `view` gates on **`procurement.quotations.view`**
 * (§101 / §208 — strictly stronger than `procurement.access`); the handler adds
 * an ADR-133 refinement at the RFQ's facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: Quotation::class)]
final readonly class GetRfqQuotations
{
    public function __construct(public string $rfqId)
    {
        if (! Str::isUuid($rfqId)) {
            throw new InvalidArgumentException('GetRfqQuotations::$rfqId must be a UUID (IC §3).');
        }
    }
}
