<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\RecoveryHandoffRepository;
use App\Domains\Theatre\Domain\Service\RecoveryHandoffLifecycle;

/**
 * Orchestrates `AcknowledgeRecoveryHandoff` (ADR-156 D-D). Loaded
 * `getByIdForUpdate` — a second acknowledge on an already-ACKNOWLEDGED
 * handoff would dirty nothing, so the FOR UPDATE lock serialises concurrent
 * acknowledgements (the T.2 lesson); the guard rejects it loudly anyway.
 */
final class AcknowledgeRecoveryHandoffHandler
{
    public function __construct(
        private readonly RecoveryHandoffRepository $handoffs,
        private readonly RecoveryHandoffLifecycle $lifecycle,
    ) {}

    public function __invoke(AcknowledgeRecoveryHandoff $command): void
    {
        $handoff = $this->handoffs->getByIdForUpdate($command->handoffId);

        $this->lifecycle->assertCanAcknowledge((string) $handoff->status);

        $handoff->acknowledge($command->actorId);

        $this->handoffs->save($handoff);
    }
}
