<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

/**
 * The `ReverseDispensing` command result (ADR-185 D-D).
 */
final readonly class ReverseDispensingResult
{
    public function __construct(
        public string $reversalId,
        public string $reversalNumber,
        public string $reversalMovementId,
        public string $orderStatus,
    ) {}
}
