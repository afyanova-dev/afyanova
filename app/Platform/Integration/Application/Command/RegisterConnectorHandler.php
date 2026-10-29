<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\ConnectorStatus;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;

final readonly class RegisterConnectorHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
    ) {}

    public function __invoke(RegisterConnectorCommand $command): IntegrationConnector
    {
        $endpointConfig = $command->config ?? [];
        if ($command->baseUrl !== null) {
            $endpointConfig['base_url'] = $command->baseUrl;
        }

        $connector = new IntegrationConnector([
            'tenant_id' => $command->tenantId,
            'name' => $command->name,
            'key' => $command->key,
            'family' => $command->family,
            'direction' => $command->direction,
            'endpoint_config' => $endpointConfig,
            'auth_type' => $command->authType,
            'payload_sensitivity' => $command->payloadSensitivity,
            'status' => ConnectorStatus::ENABLED,
        ]);

        $this->connectorRepository->save($connector);

        return $connector;
    }
}
