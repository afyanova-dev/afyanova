<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * An aggregate does not exist (within the current tenant scope).
 * Maps to HTTP 404 (§15). A repository's getById() throws this.
 */
class NotFound extends DomainException
{
    public static function aggregate(string $type, string $id): self
    {
        return new self(sprintf('%s [%s] not found.', class_basename($type), $id));
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
