<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The coverage rules for a plan — current + history, newest first, optionally
 * one category (21-insurance-and-payer-domain.md §31, §162; ADR-200 D-D).
 * `view` gates on the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: PlanCoverageRule::class)]
final readonly class GetPlanCoverageRules
{
    public ?string $serviceCategory;

    public function __construct(public string $planId, ?string $serviceCategory = null)
    {
        if (! Str::isUuid($planId)) {
            throw new InvalidArgumentException('GetPlanCoverageRules::$planId must be a UUID (IC §3).');
        }

        if ($serviceCategory !== null && ! CoveredServiceCategory::isValid($serviceCategory)) {
            throw new InvalidArgumentException('GetPlanCoverageRules::$serviceCategory must be a valid category or null.');
        }

        $this->serviceCategory = $serviceCategory !== null ? strtoupper(trim($serviceCategory)) : null;
    }
}
