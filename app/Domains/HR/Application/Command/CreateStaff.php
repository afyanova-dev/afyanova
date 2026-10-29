<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class CreateStaff implements AuthorizesAgainst
{
    public string $staffId;

    public function __construct(
        public string $personId,
        ?string $staffId = null,
        public ?string $primaryCadreId = null,
        public ?string $primaryPositionId = null,
    ) {
        $this->staffId = $staffId ?? (string) Str::uuid();
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
