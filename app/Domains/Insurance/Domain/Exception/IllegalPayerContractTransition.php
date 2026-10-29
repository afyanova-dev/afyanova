<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An illegal lifecycle transition on a payer contract (ADR-203).
 * Maps to HTTP 409.
 */
final class IllegalPayerContractTransition extends DomainException
{
    public static function cannotTransition(string $contractId, string $currentStatus, string $targetStatus): self
    {
        return new self(sprintf(
            'Payer contract [%s] cannot transition from [%s] to [%s].',
            $contractId,
            $currentStatus,
            $targetStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_payer_contract_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
