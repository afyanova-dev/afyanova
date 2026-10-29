<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Feedback still awaiting completion for referrals sourced at a facility
 * (26-care-coordination-domain.md §10; ADR-151 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Referral::class)]
final readonly class GetReferralFeedbackDue
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetReferralFeedbackDue::$facilityId must be a UUID (IC §3).');
        }
    }
}
