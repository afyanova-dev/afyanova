<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts\Dto;

final readonly class RoomSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {}
}
