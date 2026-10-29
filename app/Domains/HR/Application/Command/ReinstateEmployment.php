<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ReinstateEmployment implements AuthorizesAgainst
{
    public function __construct(
        public string $employmentId,
    ) {}

    public function policyAbility(): string
    {
        return 'reinstate';
    }

    public function subjectId(): string
    {
        return $this->employmentId;
    }
}
