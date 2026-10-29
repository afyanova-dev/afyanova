<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\AppointmentType;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: AppointmentType::class)]
final readonly class GetAppointmentTypes
{
    public function __construct(
        public ?string $facilityId = null,
    ) {}
}
