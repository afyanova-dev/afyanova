<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

final readonly class RecordPrescribingDecisionResult
{
    public function __construct(
        public string $prescribingDecisionId,
    ) {}
}
