<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class DefinePosition implements AuthorizesAgainst
{
    public string $positionId;

    public function __construct(
        public string $code,
        public string $title,
        public string $cadreId,
        ?string $positionId = null,
        public ?string $description = null,
        public bool $isActive = true,
    ) {
        $this->positionId = $positionId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
