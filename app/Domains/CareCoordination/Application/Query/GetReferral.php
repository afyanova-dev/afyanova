<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one referral (ADR-151 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Referral::class)]
final readonly class GetReferral
{
    public function __construct(public string $referralId)
    {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('GetReferral::$referralId must be a UUID (IC §3).');
        }
    }
}
