<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Domains\Procurement\Domain\Quantity;

/**
 * Pure validator for an `AwardRfq` allocation (19-procurement-domain.md §37,
 * §38, §39; ADR-173 D-D). No I/O — the handler assembles the RFQ / quotation
 * data and passes plain arrays.
 *
 * Split award (§38) = ≥ 2 entries for one `rfqLineId` with different
 * `quotationId`. Partial award (§39) = Σ `awardedQty` for a line < that line's
 * requested quantity — allowed; the shortfall is the caller's concern.
 */
final class RfqAwardValidator
{
    /**
     * @param  array<string, string>  $rfqLineQuantities  rfqLineId => requested qty (numeric string)
     * @param  list<string>  $submittedQuotationIds
     * @param  array<string, array<string, string>>  $quotationLineOffers  quotationId => (rfqLineId => offered qty)
     * @param  list<array{rfqLineId: string, quotationId: string, awardedQty: string}>  $allocation
     */
    public function assertValid(
        array $rfqLineQuantities,
        array $submittedQuotationIds,
        array $quotationLineOffers,
        array $allocation,
    ): void {
        if ($allocation === []) {
            throw RfqAwardInvalid::empty();
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, string> $perLineTotal */
        $perLineTotal = [];

        foreach ($allocation as $entry) {
            $rfqLineId = $entry['rfqLineId'];
            $quotationId = $entry['quotationId'];
            $awardedQty = $entry['awardedQty'];

            $key = $rfqLineId.'|'.$quotationId;
            if (isset($seen[$key])) {
                throw RfqAwardInvalid::duplicateEntry($rfqLineId, $quotationId);
            }
            $seen[$key] = true;

            if (! array_key_exists($rfqLineId, $rfqLineQuantities)) {
                throw RfqAwardInvalid::unknownRfqLine($rfqLineId);
            }

            if (! in_array($quotationId, $submittedQuotationIds, true)) {
                throw RfqAwardInvalid::quotationNotSubmitted($quotationId);
            }

            $offered = $quotationLineOffers[$quotationId][$rfqLineId] ?? null;
            if ($offered === null) {
                throw RfqAwardInvalid::unquotedLine($quotationId, $rfqLineId);
            }

            if (! is_numeric($awardedQty) || ! Quantity::isPositive($awardedQty)) {
                throw RfqAwardInvalid::nonPositiveQty($rfqLineId);
            }

            if (! Quantity::gte($offered, $awardedQty)) {
                throw RfqAwardInvalid::overQuotationLine($rfqLineId, $quotationId);
            }

            $perLineTotal[$rfqLineId] = Quantity::add($perLineTotal[$rfqLineId] ?? '0', $awardedQty);
        }

        foreach ($perLineTotal as $rfqLineId => $total) {
            if (! Quantity::gte($rfqLineQuantities[$rfqLineId], $total)) {
                throw RfqAwardInvalid::overRfqLine($rfqLineId);
            }
        }
    }
}
