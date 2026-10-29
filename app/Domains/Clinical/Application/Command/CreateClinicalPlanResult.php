<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

final readonly class CreateClinicalPlanResult
{
    public function __construct(
        public string $clinicalPlanId,
    ) {}
}
