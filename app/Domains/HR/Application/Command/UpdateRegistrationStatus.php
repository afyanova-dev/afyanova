<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\RegistrationStatus;
use App\Shared\Bus\AuthorizesAgainst;

final readonly class UpdateRegistrationStatus implements AuthorizesAgainst
{
    public RegistrationStatus $status;

    public function __construct(
        public string $registrationId,
        string|RegistrationStatus $status,
        public ?string $reason = null,
    ) {
        $this->status = is_string($status) ? RegistrationStatus::from($status) : $status;
    }

    public function policyAbility(): string
    {
        return 'updateStatus';
    }

    public function subjectId(): string
    {
        return $this->registrationId;
    }
}
