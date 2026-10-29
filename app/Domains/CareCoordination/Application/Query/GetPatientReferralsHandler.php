<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPatientReferralsHandler
{
    public function __construct(
        private readonly ReferralReader $reader,
    ) {}

    /**
     * @return list<ReferralSummary>
     */
    public function __invoke(GetPatientReferrals $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPatientReferrals dispatched without an authenticated user (IC §6.1).');
        }

        if (! $user->hasPermissionKey('care_coordination.access')) {
            throw NotFound::aggregate(Referral::class, $query->patientId);
        }

        return $this->reader->forPatient($query->patientId);
    }
}
