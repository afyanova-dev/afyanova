<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An `AmendContract` change entry is not applicable (19-procurement-domain.md
 * §195, §248; ADR-178 D-C / D-H). Maps to HTTP 409.
 */
final class ContractAmendmentInvalid extends DomainException
{
    public static function unknownLine(string $contractId, string $contractLineId): self
    {
        return new self(sprintf('Contract line [%s] is not on contract [%s].', $contractLineId, $contractId));
    }

    public static function unamendableField(string $field): self
    {
        return new self(sprintf('Field [%s] cannot be changed by AmendContract — use RenewContract or terminate + recreate.', $field));
    }

    public function errorCode(): string
    {
        return 'contract_amendment_invalid';
    }
}
