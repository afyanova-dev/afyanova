<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An authorization query or command named an authorization that does not exist
 * in the current tenant scope (ADR-202 D-A / D-C). Maps to HTTP 404.
 */
final class AuthorizationNotFound extends DomainException
{
    public static function id(string $authorizationId): self
    {
        return new self(sprintf('Authorization [%s] not found.', $authorizationId));
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
