<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\CredentialStatus;
use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Model\IntegrationCredential;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\CredentialRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RotateCredentialHandler
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
        private CredentialRepository $credentialRepository,
    ) {}

    public function __invoke(RotateCredentialCommand $command): IntegrationCredential
    {
        $connector = $this->connectorRepository->findById($command->connectorId);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$command->connectorId}' not found");
        }

        // Check if there is an existing active credential and mark it as ROTATING
        $activeCredential = $this->credentialRepository->findActiveByConnector($connector->id);
        if ($activeCredential !== null) {
            $activeCredential->status = CredentialStatus::ROTATING;
            $this->credentialRepository->save($activeCredential);
        }

        // Create new active credential
        $newCredential = new IntegrationCredential([
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'key_identifier' => $command->keyIdentifier ?? ('key-'.Str::random(8)),
            'algorithm' => $command->algorithm,
            'secret_ref' => $command->secretRef,
            'status' => CredentialStatus::ACTIVE,
            'expires_at' => $command->expiresAt,
            'last_rotated_at' => CarbonImmutable::now(),
        ]);

        $this->credentialRepository->save($newCredential);

        return $newCredential;
    }
}
