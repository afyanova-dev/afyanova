<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Application\Service\IdMappingService;
use InvalidArgumentException;

final readonly class ResolveIdMappingHandler
{
    public function __construct(
        private IdMappingService $idMappingService,
    ) {}

    public function __invoke(ResolveIdMappingQuery $query): string
    {
        if ($query->externalId !== null) {
            return $this->idMappingService->resolveInternalId(
                connectorId: $query->connectorId,
                externalId: $query->externalId,
            );
        }

        if ($query->internalDomain !== null && $query->internalId !== null) {
            return $this->idMappingService->resolveExternalId(
                connectorId: $query->connectorId,
                internalDomain: $query->internalDomain,
                internalId: $query->internalId,
            );
        }

        throw new InvalidArgumentException('Either externalId or internalDomain and internalId must be provided');
    }
}
