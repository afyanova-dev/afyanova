<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Model\DocumentAccessGrant;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use Carbon\CarbonImmutable;

final class GrantDocumentAccessHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly AccessGrantRepositoryInterface $accessGrantRepository,
    ) {}

    public function __invoke(GrantDocumentAccessCommand $command): void
    {
        $document = $this->documentRepository->findById($command->documentId);
        if ($document === null) {
            throw new DocumentNotFoundException("Document '{$command->documentId}' not found.");
        }

        $grant = DocumentAccessGrant::createGrant(
            tenantId: $command->tenantId,
            documentId: $command->documentId,
            principalId: $command->principalId,
            scope: $command->scope,
            expiresAt: $command->expiresAt,
            grantedBy: $command->grantedBy,
            grantedAt: CarbonImmutable::now(),
        );

        $this->accessGrantRepository->save($grant);
    }
}
