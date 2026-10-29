<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\Service\FinancialControlLifecycle;

/**
 * Orchestrates `RejectAdjustment` (ADR-191 G-3). `assertCanReject` (REQUESTED)
 * → `reject` → save. No ledger entry, no event.
 */
final class RejectAdjustmentHandler
{
    public function __construct(
        private readonly AdjustmentRepository $adjustments,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectAdjustment $command): void
    {
        $adjustment = $this->adjustments->getByIdForUpdate($command->adjustmentId);

        $this->lifecycle->assertCanReject((string) $adjustment->status);

        $adjustment->reject($command->actorId, $command->reasonCode);

        $this->adjustments->save($adjustment);
    }
}
