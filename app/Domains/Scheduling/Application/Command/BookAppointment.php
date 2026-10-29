<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class BookAppointment implements AuthorizesAgainst
{
    public string $appointmentId;

    public function __construct(
        public string $patientId,
        public string $resourceId,
        public string $appointmentTypeId,
        public string $scheduledStart,
        public string $scheduledEnd,
        public ?string $slotId = null,
        public ?string $reason = null,
        public ?string $facilityId = null,
        ?string $appointmentId = null,
    ) {
        $this->appointmentId = $appointmentId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'book';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
