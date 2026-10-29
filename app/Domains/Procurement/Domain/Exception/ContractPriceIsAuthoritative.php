<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * INV-price (19-procurement-domain.md §41, §303; ADR-176 D4 / ADR-177 D-E). An
 * `AmendPurchaseOrder` tried to set a contract-linked PO line's `unit_price` to
 * a value other than the linked contract line's `agreed_unit_price` — the
 * contract price is authoritative for the life of the line. Maps to HTTP 409.
 */
final class ContractPriceIsAuthoritative extends DomainException
{
    public static function forPoLine(string $poLineId, string $attempted, string $contractPrice): self
    {
        return new self(sprintf(
            'PO line [%s] is contract-priced at [%s] — it cannot be amended to [%s].',
            $poLineId,
            $contractPrice,
            $attempted,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_price_is_authoritative';
    }
}
