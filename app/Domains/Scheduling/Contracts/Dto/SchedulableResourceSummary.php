<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class SchedulableResourceSummary
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $resourceType,
        public string $resourceRef,
        public string $name,
        public bool $isActive,
    ) {}
}
