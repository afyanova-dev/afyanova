<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A query or command named a member coverage that does not exist in the
 * current tenant scope (ADR-199 D-A / D-C). Maps to HTTP 404.
 */
final class MemberCoverageNotFound extends DomainException
{
    public static function id(string $memberCoverageId): self
    {
        return new self(sprintf('Member coverage [%s] not found.', $memberCoverageId));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
