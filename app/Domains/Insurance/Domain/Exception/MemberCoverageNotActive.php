<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RequestAuthorization` targeted a member coverage that is not ACTIVE
 * (ADR-202 D-B). Maps to HTTP 422.
 */
final class MemberCoverageNotActive extends DomainException
{
    public static function coverage(string $memberCoverageId, string $status): self
    {
        return new self(sprintf(
            'Member coverage [%s] is not ACTIVE (current status: %s).',
            $memberCoverageId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'member_coverage_not_active';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
