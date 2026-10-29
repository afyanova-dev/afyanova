<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Service;

use App\Platform\Integration\Domain\Enum\MappingSource;
use App\Platform\Integration\Domain\Enum\VerificationStatus;
use App\Platform\Integration\Domain\Exception\MappingNotFoundException;
use App\Platform\Integration\Domain\Model\IdMapping;
use App\Platform\Integration\Domain\Repository\IdMappingRepository;
use Carbon\CarbonImmutable;

final readonly class IdMappingService
{
    public function __construct(
        private IdMappingRepository $idMappingRepository,
    ) {}

    public function resolveInternalId(string $connectorId, string $externalId): string
    {
        $mapping = $this->idMappingRepository->findByExternal($connectorId, $externalId);
        if ($mapping === null) {
            throw new MappingNotFoundException("No internal ID mapping found for external ID '{$externalId}' on connector '{$connectorId}'");
        }

        $mapping->touchLastSeen();
        $this->idMappingRepository->save($mapping);

        return $mapping->internal_id;
    }

    public function resolveExternalId(string $connectorId, string $internalDomain, string $internalId): string
    {
        $mapping = $this->idMappingRepository->findByInternal($connectorId, $internalDomain, $internalId);
        if ($mapping === null) {
            throw new MappingNotFoundException("No external ID mapping found for {$internalDomain}:{$internalId} on connector '{$connectorId}'");
        }

        $mapping->touchLastSeen();
        $this->idMappingRepository->save($mapping);

        return $mapping->external_id;
    }

    public function upsertMapping(
        string $tenantId,
        string $connectorId,
        string $externalId,
        string $internalDomain,
        string $internalId,
        MappingSource $source = MappingSource::DOMAIN_PROVIDED,
        VerificationStatus $status = VerificationStatus::VERIFIED,
        float $confidence = 1.0,
    ): IdMapping {
        $mapping = $this->idMappingRepository->findByExternal($connectorId, $externalId);

        $now = CarbonImmutable::now();

        if ($mapping === null) {
            $mapping = new IdMapping([
                'tenant_id' => $tenantId,
                'connector_id' => $connectorId,
                'external_id' => $externalId,
                'internal_domain' => $internalDomain,
                'internal_id' => $internalId,
                'mapping_source' => $source,
                'verification_status' => $status,
                'confidence' => $confidence,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            $mapping->internal_domain = $internalDomain;
            $mapping->internal_id = $internalId;
            $mapping->mapping_source = $source;
            $mapping->verification_status = $status;
            $mapping->confidence = $confidence;
            $mapping->last_seen_at = $now;
        }

        $this->idMappingRepository->save($mapping);

        return $mapping;
    }
}
