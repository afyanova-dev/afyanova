<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\PatientStatus;

final readonly class RequestUnmergeResult
{
    public function __construct(
        public string $mergeId,
        public string $nonSurvivingPatientId,
        public PatientStatus $restoredStatus,
    ) {}
}
