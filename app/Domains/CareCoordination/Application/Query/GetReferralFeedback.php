<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The feedback thread for a referral (26-care-coordination-domain.md §10;
 * ADR-151 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Referral::class)]
final readonly class GetReferralFeedback
{
    public function __construct(public string $referralId)
    {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('GetReferralFeedback::$referralId must be a UUID (IC §3).');
        }
    }
}
