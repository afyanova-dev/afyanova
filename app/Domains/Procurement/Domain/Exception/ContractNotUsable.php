<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreatePurchaseOrder(contractId)` cited a contract that is not ACTIVE, or
 * whose `expiry_date` is in the past — §194 (Invariant 32): an expired contract
 * cannot SILENTLY authorize a new purchase (ADR-176 D-D). Maps to HTTP 409.
 */
final class ContractNotUsable extends DomainException
{
    public static function status(string $contractId, string $status): self
    {
        return new self(sprintf('Contract [%s] is [%s] — only an ACTIVE, unexpired contract can price a PO (§194; ADR-176 D-D).', $contractId, $status));
    }

    public static function expired(string $contractId, string $expiryDate): self
    {
        return new self(sprintf('Contract [%s] expired on [%s] — it cannot price a new PO (§194 / Inv 32; ADR-176 D-D).', $contractId, $expiryDate));
    }

    public function errorCode(): string
    {
        return 'contract_not_usable';
    }
}
