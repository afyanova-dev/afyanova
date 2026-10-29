<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The `principal_member_coverage_id` on a `RegisterMemberCoverage` is
 * inconsistent with the relationship (ADR-199 D-E; §18): a PRIMARY_MEMBER must
 * carry no principal; a dependent's principal must resolve in-tenant. Maps to
 * HTTP 409.
 */
final class PrincipalCoverageInvalid extends DomainException
{
    public static function primaryMemberHasPrincipal(): self
    {
        return new self('A PRIMARY_MEMBER coverage must not reference a principal member coverage (§18).');
    }

    public static function unknown(string $principalMemberCoverageId): self
    {
        return new self(sprintf(
            'Principal member coverage [%s] does not exist in this tenant (§18).',
            $principalMemberCoverageId,
        ));
    }

    public function errorCode(): string
    {
        return 'principal_coverage_invalid';
    }
}
