<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: WaitlistEntry::class)]
final readonly class GetFacilityWaitlist
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
        public ?string $appointmentTypeId = null,
        public ?string $resourceId = null,
    ) {}
}
