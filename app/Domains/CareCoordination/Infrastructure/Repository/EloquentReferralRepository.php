<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Repository;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of ReferralRepository (ADR-151 D-D).
 */
final class EloquentReferralRepository extends EloquentRepository implements ReferralRepository
{
    protected function model(): string
    {
        return Referral::class;
    }

    public function getById(string $id): Referral
    {
        return Referral::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Referral::class, $id);
    }

    public function getByIdForUpdate(string $id): Referral
    {
        return Referral::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Referral::class, $id);
    }
}
