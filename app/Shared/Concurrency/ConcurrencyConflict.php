<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Shared\Domain\DomainException;

/**
 * Optimistic-lock failure — the aggregate changed between load and save
 * (IMPLEMENTATION-CONTRACTS.md §9.1). Maps to HTTP 409 { code: "conflict" }.
 */
final class ConcurrencyConflict extends DomainException
{
    public static function on(string $aggregate, string $id): self
    {
        return new self(sprintf('%s [%s] was modified concurrently. Reload and retry.', class_basename($aggregate), $id));
    }

    public function errorCode(): string
    {
        return 'conflict';
    }
}
