<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabCriticalResultRepository;
use App\Domains\Laboratory\Domain\Service\LabCriticalResultLifecycle;

final class AcknowledgeCriticalResultHandler
{
    public function __construct(
        private readonly LabCriticalResultRepository $criticals,
        private readonly LabCriticalResultLifecycle $lifecycle,
    ) {}

    public function __invoke(AcknowledgeCriticalResult $command): void
    {
        $critical = $this->criticals->getByIdForUpdate($command->criticalResultId);

        $this->lifecycle->assertCanAcknowledge((string) $critical->status);

        $critical->acknowledge($command->actorId, $command->note);

        $this->criticals->save($critical);
    }
}
