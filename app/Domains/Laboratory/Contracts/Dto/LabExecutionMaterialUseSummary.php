<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

final readonly class LabExecutionMaterialUseSummary
{
    public function __construct(
        public string $useId,
        public string $movementId,
        public string $locationId,
        public ?string $batchNumber,
        public string $quantity,
        public string $status,
        public ?string $failureMessage,
        public ?string $consumedAt,
    ) {}
}
