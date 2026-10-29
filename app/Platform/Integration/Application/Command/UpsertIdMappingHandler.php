<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Service\IdMappingService;
use App\Platform\Integration\Domain\Model\IdMapping;

final readonly class UpsertIdMappingHandler
{
    public function __construct(
        private IdMappingService $idMappingService,
    ) {}

    public function __invoke(UpsertIdMappingCommand $command): IdMapping
    {
        return $this->idMappingService->upsertMapping(
            tenantId: $command->tenantId,
            connectorId: $command->connectorId,
            externalId: $command->externalId,
            internalDomain: $command->internalDomain,
            internalId: $command->internalId,
            source: $command->source,
            status: $command->status,
            confidence: $command->confidence,
        );
    }
}
