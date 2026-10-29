<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Shared\Domain\DomainException;

/**
 * VerifyPatientIdentifier was issued against an identifier that is not
 * UNVERIFIED (10-patient-domain.md §42). Verification promotes entered data
 * to evidence exactly once; an EXPIRED / REVOKED / already-VERIFIED row is not
 * re-verifiable through this command. Maps to HTTP 409 (§15).
 */
final class IdentifierNotPendingVerification extends DomainException
{
    public static function inState(IdentifierVerificationStatus $current): self
    {
        return new self(sprintf(
            'Only an UNVERIFIED identifier can be verified; this one is %s (§42).',
            $current->value,
        ));
    }

    public function errorCode(): string
    {
        return 'identifier_not_pending_verification';
    }
}
