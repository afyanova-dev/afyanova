<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `procurement:configure-evaluation` was given values that break the evaluation
 * scoring invariants (19-procurement-domain.md §36; ADR-175 D-B). Maps to
 * HTTP 422 — but in practice this surfaces only on the console.
 */
final class EvaluationSettingsInvalid extends DomainException
{
    public static function weightOutOfRange(string $dimension, int $weight): self
    {
        return new self(sprintf('%s weight must be 0..100, got %d.', $dimension, $weight));
    }

    public static function noPositiveWeight(): self
    {
        return new self('At least one dimension weight must be > 0 — it is the overall-score denominator.');
    }

    public static function noMandatoryDimension(): self
    {
        return new self('At least one dimension must be mandatory.');
    }

    public static function passThresholdOutOfRange(int $threshold): self
    {
        return new self(sprintf('pass_threshold must be 0..100, got %d.', $threshold));
    }

    public static function requiredAboveNegative(string $amount): self
    {
        return new self(sprintf('evaluation_required_above must be a non-negative amount, got [%s].', $amount));
    }

    public function errorCode(): string
    {
        return 'evaluation_settings_invalid';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
