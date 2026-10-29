<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: ClinicalPlan::class)]
final readonly class GetClinicalPlan
{
    public function __construct(public string $planId)
    {
        if (! Str::isUuid($planId)) {
            throw new InvalidArgumentException('GetClinicalPlan::$planId must be a UUID (IC §3).');
        }
    }
}
