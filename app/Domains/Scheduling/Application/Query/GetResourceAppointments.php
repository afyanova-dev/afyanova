<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Appointment::class)]
final readonly class GetResourceAppointments
{
    public function __construct(
        public string $resourceId,
        public ?string $date = null,
    ) {}
}
