<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An operation requiring an ACTIVE contract found it in another status (ADR-203).
 * Maps to HTTP 422.
 */
final class PayerContractNotActive extends DomainException
{
    public static function forContract(string $contractId, string $status): self
    {
        return new self(sprintf(
            'Payer contract [%s] is [%s]; only ACTIVE contracts permit this operation.',
            $contractId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'payer_contract_not_active';
    }
}
