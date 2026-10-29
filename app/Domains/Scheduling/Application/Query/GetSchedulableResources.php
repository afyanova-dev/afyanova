<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: SchedulableResource::class)]
final readonly class GetSchedulableResources
{
    public function __construct(
        public string $facilityId,
        public ?string $resourceType = null,
    ) {}
}
