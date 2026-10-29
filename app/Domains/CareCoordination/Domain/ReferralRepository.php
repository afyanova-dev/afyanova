<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Referral aggregate (ADR-151 D-D).
 */
interface ReferralRepository extends Repository
{
    public function getById(string $id): Referral;

    public function getByIdForUpdate(string $id): Referral;
}
