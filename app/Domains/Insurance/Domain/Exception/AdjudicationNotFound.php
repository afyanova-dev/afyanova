<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An adjudication was not found in the current tenant scope (ADR-205).
 * Maps to HTTP 404.
 */
final class AdjudicationNotFound extends DomainException
{
    public static function id(string $adjudicationId): self
    {
        return new self(sprintf('Adjudication [%s] not found.', $adjudicationId));
    }

    public function errorCode(): string
    {
        return 'adjudication_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
