<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class LinkStaffToUser implements AuthorizesAgainst
{
    public string $linkId;

    public function __construct(
        public string $staffId,
        public string $userId,
        public ?string $linkedBy = null,
        ?string $linkId = null,
    ) {
        $this->linkId = $linkId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'link';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
