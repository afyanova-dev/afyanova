<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;
use Carbon\CarbonImmutable;

final class ReleaseRetentionRequirementHandler
{
    public function __construct(
        private readonly RetentionRequirementRepositoryInterface $retentionRepository,
    ) {}

    public function __invoke(ReleaseRetentionRequirementCommand $command): void
    {
        $requirement = $this->retentionRepository->findById($command->requirementId);
        if ($requirement === null) {
            throw new DocumentNotFoundException("Retention requirement '{$command->requirementId}' not found.");
        }

        $requirement->release(CarbonImmutable::now());
        $this->retentionRepository->save($requirement);
    }
}
