<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Claim;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Evaluates and returns claim readiness explainability without mutating the claim
 * (21-insurance-and-payer-domain.md §217, §218; ADR-204).
 * `view` gates on coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Claim::class)]
final readonly class GetClaimReadiness
{
    public function __construct(public string $claimId)
    {
        if (! Str::isUuid($claimId)) {
            throw new InvalidArgumentException('GetClaimReadiness::$claimId must be a UUID (IC §3).');
        }
    }
}
