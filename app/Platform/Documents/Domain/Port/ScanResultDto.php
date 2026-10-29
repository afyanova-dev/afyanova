<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Port;

final readonly class ScanResultDto
{
    public function __construct(
        public bool $isClean,
        public ?string $threatName = null,
    ) {}
}
