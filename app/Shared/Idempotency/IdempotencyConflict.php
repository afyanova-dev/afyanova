<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Domain\DomainException;

/**
 * Thrown when an idempotency key is currently in progress (IMPLEMENTATION-CONTRACTS.md §5 step 3, §15).
 * Maps to HTTP 409 { code: "in_progress" }.
 */
final class IdempotencyConflict extends DomainException
{
    public static function keyInProgress(string $scope, string $key): self
    {
        return new self(sprintf('Operation [%s:%s] is currently in progress. Retry later.', $scope, $key));
    }

    public function errorCode(): string
    {
        return 'in_progress';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
