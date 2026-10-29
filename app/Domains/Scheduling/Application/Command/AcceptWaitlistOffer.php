<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class AcceptWaitlistOffer implements AuthorizesAgainst
{
    public string $appointmentId;

    public function __construct(
        public string $waitlistEntryId,
        ?string $appointmentId = null,
    ) {
        $this->appointmentId = $appointmentId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->waitlistEntryId;
    }
}
