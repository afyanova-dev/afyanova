<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class UpdatePosition implements AuthorizesAgainst
{
    public function __construct(
        public string $positionId,
        public string $title,
        public string $cadreId,
        public ?string $description = null,
        public bool $isActive = true,
    ) {}

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->positionId;
    }
}
