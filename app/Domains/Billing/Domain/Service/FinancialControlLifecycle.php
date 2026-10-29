<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Service;

use App\Domains\Billing\Domain\Exception\IllegalFinancialControlTransition;
use App\Domains\Billing\Domain\ValueObject\FinancialControlStatus;

/**
 * The shared REQUESTED → APPLIED → REVERSED (+ REJECTED) state machine for
 * Bill.7 write-offs and adjustments (ADR-191 G-3). Pure domain logic, no I/O —
 * the `FinancialAccountLifecycle` precedent.
 */
final class FinancialControlLifecycle
{
    public function assertCanApprove(string $status): void
    {
        if ($status !== FinancialControlStatus::REQUESTED) {
            throw IllegalFinancialControlTransition::fromStatus('approve', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if ($status !== FinancialControlStatus::REQUESTED) {
            throw IllegalFinancialControlTransition::fromStatus('reject', $status);
        }
    }

    public function assertCanReverse(string $status): void
    {
        if ($status !== FinancialControlStatus::APPLIED) {
            throw IllegalFinancialControlTransition::fromStatus('reverse', $status);
        }
    }
}
