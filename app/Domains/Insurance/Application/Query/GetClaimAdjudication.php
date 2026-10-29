<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the active adjudication for a claim by claim ID (21-insurance-and-payer-domain.md §84; ADR-205).
 * `view` gates on coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Adjudication::class)]
final readonly class GetClaimAdjudication
{
    public function __construct(public string $claimId)
    {
        if (! Str::isUuid($claimId)) {
            throw new InvalidArgumentException('GetClaimAdjudication::$claimId must be a UUID (IC §3).');
        }
    }
}
