<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: ClinicalPlan::class)]
final readonly class GetPatientClinicalPlans
{
    public function __construct(
        public string $patientId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientClinicalPlans::$patientId must be a UUID (IC §3).');
        }

        if ($status !== null && ! ClinicalPlanStatus::isValid($status)) {
            throw new InvalidArgumentException(sprintf(
                'GetPatientClinicalPlans::$status must be a valid ClinicalPlanStatus or null, got [%s] (ADR-143 D-E).',
                $status,
            ));
        }
    }
}
