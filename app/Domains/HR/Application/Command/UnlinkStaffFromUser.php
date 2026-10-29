<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class UnlinkStaffFromUser implements AuthorizesAgainst
{
    public function __construct(
        public string $staffId,
        public ?string $reason = null,
        public ?string $unlinkedBy = null,
    ) {}

    public function policyAbility(): string
    {
        return 'unlink';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
