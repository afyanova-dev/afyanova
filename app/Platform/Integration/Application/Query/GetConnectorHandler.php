<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;

final readonly class GetConnectorHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
    ) {}

    public function __invoke(GetConnectorQuery $query): ?IntegrationConnector
    {
        return $this->connectorRepository->findById($query->id);
    }
}
