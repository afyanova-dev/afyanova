<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class SuspendStaff implements AuthorizesAgainst
{
    public function __construct(
        public string $staffId,
        public ?string $reason = null,
    ) {}

    public function policyAbility(): string
    {
        return 'suspend';
    }

    public function subjectId(): string
    {
        return $this->staffId;
    }
}
