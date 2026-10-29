<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Evaluate a plan's coverage of a service category for a patient
 * (21-insurance-and-payer-domain.md §31, §124, §225; ADR-200 D-C). A PURE
 * read-model query — no side effects, no persisted decision. `view` gates on
 * the coarse `insurance.access`.
 *
 * `chargeAmount` (optional) turns on the ILLUSTRATIVE payer/patient split
 * estimate; `onDate` (optional) defaults to today.
 */
#[ProtectedQuery(ability: 'view', subjectType: PlanCoverageRule::class)]
final readonly class EvaluateCoverage
{
    public string $serviceCategory;

    /** @var numeric-string|null */
    public ?string $chargeAmount;

    public CarbonImmutable $onDate;

    public function __construct(
        public string $patientId,
        public string $planId,
        string $serviceCategory,
        ?string $chargeAmount = null,
        ?string $onDate = null,
        public ?string $facilityId = null,
    ) {
        if (! Str::isUuid($patientId) || ! Str::isUuid($planId)) {
            throw new InvalidArgumentException('EvaluateCoverage::$patientId and $planId must be UUIDs (IC §3).');
        }

        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('EvaluateCoverage::$facilityId must be a UUID or null (IC §3).');
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);

        if ($chargeAmount !== null) {
            $normalised = Money::of($chargeAmount);
            if (Money::isNegative($normalised)) {
                throw new InvalidArgumentException('EvaluateCoverage::$chargeAmount must not be negative (ADR-200 D-C).');
            }
            $this->chargeAmount = $normalised;
        } else {
            $this->chargeAmount = null;
        }

        if ($onDate !== null && trim($onDate) !== '') {
            try {
                $this->onDate = CarbonImmutable::parse($onDate)->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('EvaluateCoverage::$onDate must be a valid date (ADR-200 D-C).');
            }
        } else {
            $this->onDate = CarbonImmutable::now()->startOfDay();
        }
    }
}
