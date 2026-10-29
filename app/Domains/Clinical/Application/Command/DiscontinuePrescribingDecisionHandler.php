<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\PrescribingDecisionRepository;

final class DiscontinuePrescribingDecisionHandler
{
    public function __construct(
        private readonly PrescribingDecisionRepository $decisions,
    ) {}

    public function __invoke(DiscontinuePrescribingDecision $command): void
    {
        $decision = $this->decisions->getByIdForUpdate($command->prescribingDecisionId);

        $decision->discontinue($command->reason);

        $this->decisions->save($decision);
    }
}
