<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AmendContract` tried to set an `expiry_date` that is not strictly later than
 * the contract's current `expiry_date` (19-procurement-domain.md §194, §195;
 * ADR-178 D-C / D-J). Shortening a live contract's term needs
 * `TerminateContract` + a fresh contract. Maps to HTTP 409.
 */
final class ContractExpiryNotExtended extends DomainException
{
    public static function forContract(string $contractId, string $current, string $attempted): self
    {
        return new self(sprintf(
            'Contract [%s] expiry_date [%s] can only be extended — [%s] is not later.',
            $contractId,
            $current,
            $attempted,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_expiry_not_extended';
    }
}
