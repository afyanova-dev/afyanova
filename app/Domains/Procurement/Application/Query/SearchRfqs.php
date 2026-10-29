<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Rfq;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The RFQs at a facility, optionally filtered by status (ADR-173 D-F).
 * Facility-scoped — the handler adds an ADR-133 refinement at `$facilityId`
 * (the Inv.1 `GetStockAdjustments` precedent). Metadata + award outcome only.
 */
#[ProtectedQuery(ability: 'view', subjectType: Rfq::class)]
final readonly class SearchRfqs
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchRfqs::$facilityId must be a UUID (IC §3).');
        }
    }
}
