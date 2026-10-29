<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Enum;

enum ClinicalAuthoredContext: string
{
    case Clinician = 'CLINICIAN';
    case Nursing = 'NURSING';
}
