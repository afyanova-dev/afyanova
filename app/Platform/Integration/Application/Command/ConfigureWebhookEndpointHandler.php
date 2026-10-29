<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Model\WebhookEndpoint;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\WebhookEndpointRepository;

final readonly class ConfigureWebhookEndpointHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
        private WebhookEndpointRepository $endpointRepository,
    ) {}

    public function __invoke(ConfigureWebhookEndpointCommand $command): WebhookEndpoint
    {
        $connector = $this->connectorRepository->findById($command->connectorId);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$command->connectorId}' not found");
        }

        $endpoint = $this->endpointRepository->findByPath($command->pathSlug);
        if ($endpoint === null) {
            $endpoint = new WebhookEndpoint([
                'tenant_id' => $connector->tenant_id,
                'connector_id' => $connector->id,
                'path_slug' => $command->pathSlug,
                'secret_ref' => $command->secretRef,
                'allowed_ips' => $command->allowedIps,
                'active' => $command->active,
            ]);
        } else {
            $endpoint->connector_id = $connector->id;
            $endpoint->tenant_id = $connector->tenant_id;
            $endpoint->secret_ref = $command->secretRef;
            $endpoint->allowed_ips = $command->allowedIps;
            $endpoint->active = $command->active;
        }

        $this->endpointRepository->save($endpoint);

        return $endpoint;
    }
}
