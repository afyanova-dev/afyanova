<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts\Dto;

final readonly class InitialTenantStructure
{
    public function __construct(
        public string $organizationName,
        public string $organizationCode,
        public string $facilityName,
        public string $facilityCode,
        public string $facilityType,
        public string $careLevel,
        public string $ownershipType,
        public string $actorId,
    ) {}
}
