<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class AddToWaitlist implements AuthorizesAgainst
{
    public string $waitlistEntryId;

    public function __construct(
        public string $patientId,
        public string $appointmentTypeId,
        public ?string $facilityId = null,
        public ?string $resourceId = null,
        public string $priority = 'ROUTINE',
        public ?string $preferredDateStart = null,
        public ?string $preferredDateEnd = null,
        public ?string $notes = null,
        ?string $waitlistEntryId = null,
    ) {
        $this->waitlistEntryId = $waitlistEntryId ?? (string) Str::uuid();
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
