<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * Result of an idempotency claim attempt (IMPLEMENTATION-CONTRACTS.md §5, §14).
 */
final readonly class ClaimResult
{
    private function __construct(
        public ClaimStatus $status,
        public mixed $storedResult = null,
    ) {}

    public static function claimed(): self
    {
        return new self(ClaimStatus::Claimed);
    }

    public static function completed(mixed $storedResult): self
    {
        return new self(ClaimStatus::Completed, $storedResult);
    }

    public static function inProgress(): self
    {
        return new self(ClaimStatus::InProgress);
    }

    public function isClaimed(): bool
    {
        return $this->status === ClaimStatus::Claimed;
    }

    public function isCompleted(): bool
    {
        return $this->status === ClaimStatus::Completed;
    }

    public function isInProgress(): bool
    {
        return $this->status === ClaimStatus::InProgress;
    }
}
