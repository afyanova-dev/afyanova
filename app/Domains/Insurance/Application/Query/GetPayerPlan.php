<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one payer-plan master entry (21-insurance-and-payer-domain.md §12;
 * ADR-198 D-I). `view` gates on the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Plan::class)]
final readonly class GetPayerPlan
{
    public function __construct(public string $planId)
    {
        if (! Str::isUuid($planId)) {
            throw new InvalidArgumentException('GetPayerPlan::$planId must be a UUID (IC §3).');
        }
    }
}
