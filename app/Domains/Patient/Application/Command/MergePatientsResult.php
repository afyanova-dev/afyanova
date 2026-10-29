<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class MergePatientsResult
{
    public function __construct(
        public string $mergeId,
        public string $survivingPatientId,
        public string $nonSurvivingPatientId,
    ) {}
}
