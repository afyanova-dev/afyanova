<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;

final readonly class ListConnectorsHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
    ) {}

    /**
     * @return list<IntegrationConnector>
     */
    public function __invoke(ListConnectorsQuery $query): array
    {
        return $this->connectorRepository->listAll();
    }
}
