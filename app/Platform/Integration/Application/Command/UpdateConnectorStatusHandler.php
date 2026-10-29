<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;

final readonly class UpdateConnectorStatusHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
    ) {}

    public function __invoke(UpdateConnectorStatusCommand $command): IntegrationConnector
    {
        $connector = $this->connectorRepository->findById($command->connectorId);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$command->connectorId}' not found");
        }

        $connector->status = $command->status;
        $this->connectorRepository->save($connector);

        return $connector;
    }
}
