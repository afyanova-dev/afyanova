<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Rfq;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one RFQ + its lines + invited suppliers + award outcome (ADR-173 D-F).
 * `view` gates on the coarse `procurement.access`; the handler adds an ADR-133
 * refinement at the RFQ's facility. **No competing quotation prices** — those
 * are `GetRfqQuotations` / `CompareQuotations` (§101).
 */
#[ProtectedQuery(ability: 'view', subjectType: Rfq::class)]
final readonly class GetRfq
{
    public function __construct(public string $rfqId)
    {
        if (! Str::isUuid($rfqId)) {
            throw new InvalidArgumentException('GetRfq::$rfqId must be a UUID (IC §3).');
        }
    }
}
