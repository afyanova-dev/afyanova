<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;

/**
 * Cross-domain port for Care Coordination (Domain 26).
 */
interface CareCoordinationApi
{
    /**
     * @return list<ReferralSummary>
     */
    public function searchReferrals(string $facilityId): array;
}
