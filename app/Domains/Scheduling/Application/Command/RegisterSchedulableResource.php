<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class RegisterSchedulableResource implements AuthorizesAgainst
{
    public string $resourceId;

    public ResourceType $resourceType;

    public function __construct(
        public string $facilityId,
        ResourceType|string $resourceType,
        public string $resourceRef,
        public string $name,
        public bool $isActive = true,
        ?string $resourceId = null,
    ) {
        $this->resourceId = $resourceId ?? (string) Str::uuid();
        $this->resourceType = is_string($resourceType) ? ResourceType::from($resourceType) : $resourceType;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
