<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Service\FinancialControlLifecycle;
use App\Domains\Billing\Domain\WriteOffRepository;

/**
 * Orchestrates `RejectWriteOff` (ADR-191 G-3). `getByIdForUpdate` →
 * `assertCanReject` (REQUESTED) → `reject` → save. No ledger entry, no event.
 */
final class RejectWriteOffHandler
{
    public function __construct(
        private readonly WriteOffRepository $writeOffs,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectWriteOff $command): void
    {
        $writeOff = $this->writeOffs->getByIdForUpdate($command->writeOffId);

        $this->lifecycle->assertCanReject((string) $writeOff->status);

        $writeOff->reject($command->actorId, $command->reasonCode);

        $this->writeOffs->save($writeOff);
    }
}
