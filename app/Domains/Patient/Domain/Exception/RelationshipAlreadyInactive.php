<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * RemovePatientRelationship was issued against a relationship that is already
 * INACTIVE (ADR-126 D-8). Deactivation happens once; re-removing is a no-op
 * the caller should not have to guess at. Maps to HTTP 409 (§15).
 */
final class RelationshipAlreadyInactive extends DomainException
{
    public static function forId(string $relationshipId): self
    {
        return new self(sprintf('Patient relationship [%s] is already inactive.', $relationshipId));
    }

    public function errorCode(): string
    {
        return 'relationship_already_inactive';
    }
}
