<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts\Dto;

final readonly class ProtectedRoleReference
{
    public function __construct(
        public string $id,
        public string $key,
        public string $scopeType,
    ) {}
}
