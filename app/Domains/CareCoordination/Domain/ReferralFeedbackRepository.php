<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for ReferralFeedback aggregate (ADR-151 D-D).
 */
interface ReferralFeedbackRepository extends Repository
{
    public function getById(string $id): ReferralFeedback;

    public function getByIdForUpdate(string $id): ReferralFeedback;

    /**
     * The current (latest, by sequence) feedback row for a referral — CC.1
     * creates exactly one (sequence=1), so this is that row.
     */
    public function getLatestForReferral(string $referralId): ?ReferralFeedback;
}
