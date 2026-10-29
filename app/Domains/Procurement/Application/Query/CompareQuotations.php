<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Per-RFQ-line price matrix across every quotation (ADR-173 D-F). The ranking is
 * NOT applied — §35 ("lowest price is not always the winner"); the human
 * decides. `view` gates on **`procurement.quotations.view`** (§101 / §208).
 */
#[ProtectedQuery(ability: 'view', subjectType: Quotation::class)]
final readonly class CompareQuotations
{
    public function __construct(public string $rfqId)
    {
        if (! Str::isUuid($rfqId)) {
            throw new InvalidArgumentException('CompareQuotations::$rfqId must be a UUID (IC §3).');
        }
    }
}
