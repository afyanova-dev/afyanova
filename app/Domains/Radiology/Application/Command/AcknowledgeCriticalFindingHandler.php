<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\RadiologyCriticalFindingRepository;
use App\Domains\Radiology\Domain\Service\RadiologyCriticalFindingLifecycle;

final class AcknowledgeCriticalFindingHandler
{
    public function __construct(
        private readonly RadiologyCriticalFindingRepository $findings,
        private readonly RadiologyCriticalFindingLifecycle $lifecycle,
    ) {}

    public function __invoke(AcknowledgeCriticalFinding $command): void
    {
        $finding = $this->findings->getByIdForUpdate($command->criticalFindingId);

        $this->lifecycle->assertCanAcknowledge((string) $finding->status);

        $finding->acknowledge($command->actorId, $command->note);

        $this->findings->save($finding);
    }
}
