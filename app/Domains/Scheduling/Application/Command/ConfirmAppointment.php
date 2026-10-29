<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ConfirmAppointment implements AuthorizesAgainst
{
    public function __construct(
        public string $appointmentId,
    ) {}

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->appointmentId;
    }
}
