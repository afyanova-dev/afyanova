<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetAdjustment` named an adjustment absent from the current tenant scope
 * (ADR-191 D7-D). Maps to HTTP 404.
 */
final class AdjustmentNotFound extends DomainException
{
    public static function id(string $adjustmentId): self
    {
        return new self(sprintf('Adjustment [%s] not found.', $adjustmentId));
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
