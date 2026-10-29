<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Model\RetentionRequirement;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;

final class ImposeRetentionRequirementHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly RetentionRequirementRepositoryInterface $retentionRepository,
    ) {}

    public function __invoke(ImposeRetentionRequirementCommand $command): void
    {
        $document = $this->documentRepository->findById($command->documentId);
        if ($document === null) {
            throw new DocumentNotFoundException("Document '{$command->documentId}' not found.");
        }

        $requirement = RetentionRequirement::createRequirement(
            tenantId: $command->tenantId,
            documentId: $command->documentId,
            imposedBy: $command->imposedBy,
            reason: $command->reason,
            actorId: $command->actorId,
            retainUntil: $command->retainUntil,
            indefinite: $command->indefinite,
        );

        $this->retentionRepository->save($requirement);
    }
}
