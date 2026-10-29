<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A claim cannot be matched to a remittance because the payers do not match (ADR-205).
 * Maps to HTTP 422.
 */
final class RemittancePayerMismatch extends DomainException
{
    public static function forClaimAndRemittance(string $claimId, string $claimPayerId, string $remittancePayerId): self
    {
        return new self(sprintf(
            'Claim [%s] belongs to payer [%s], but remittance is for payer [%s].',
            $claimId,
            $claimPayerId,
            $remittancePayerId,
        ));
    }

    public function errorCode(): string
    {
        return 'remittance_payer_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
