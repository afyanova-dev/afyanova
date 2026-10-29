<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class DefineAppointmentType implements AuthorizesAgainst
{
    public string $appointmentTypeId;

    public function __construct(
        public string $code,
        public string $name,
        public int $durationMinutes = 30,
        public int $bufferBeforeMinutes = 0,
        public int $bufferAfterMinutes = 0,
        public bool $isVirtual = false,
        public bool $allowPatientBooking = true,
        public bool $isActive = true,
        public ?string $facilityId = null,
        ?string $appointmentTypeId = null,
    ) {
        $this->appointmentTypeId = $appointmentTypeId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
