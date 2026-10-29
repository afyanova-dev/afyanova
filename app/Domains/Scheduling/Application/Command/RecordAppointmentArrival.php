<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class RecordAppointmentArrival implements AuthorizesAgainst
{
    public function __construct(
        public string $appointmentId,
        public ?string $arrivedAt = null,
    ) {}

    public function policyAbility(): string
    {
        return 'recordArrival';
    }

    public function subjectId(): string
    {
        return $this->appointmentId;
    }
}
