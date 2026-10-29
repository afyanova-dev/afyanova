<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Shared\Domain\DomainException;

/**
 * Another patient identity in this tenant already holds this identifier value
 * as VERIFIED (partial unique index on pat_patient_identifiers
 * (tenant_id, identifier_type, normalized_value) WHERE
 * verification_status = 'VERIFIED' — ADR-117). The verify handler catches the
 * unique violation and rethrows this DomainException (409) rather than the
 * raw QueryException, so the caller gets a resolvable identity conflict.
 */
final class DuplicateVerifiedIdentifier extends DomainException
{
    public static function for(IdentifierType $type, string $normalizedValue): self
    {
        return new self(sprintf(
            'A verified %s identifier matching [%s] already belongs to another patient in this tenant.',
            $type->value,
            $normalizedValue,
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_verified_identifier';
    }
}
