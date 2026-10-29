<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class RegisterQueueCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $facilityId,
        public string $code,
        public string $name,
        public ?string $departmentId = null,
        public ?string $serviceCapability = null,
        public string $ticketPrefix = 'A',
        public bool $isActive = true,
    ) {}

    public function policyAbility(): string
    {
        return 'register';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
