<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Repository;

use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\CareCoordination\Domain\ReferralFeedbackRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of ReferralFeedbackRepository (ADR-151 D-D).
 */
final class EloquentReferralFeedbackRepository extends EloquentRepository implements ReferralFeedbackRepository
{
    protected function model(): string
    {
        return ReferralFeedback::class;
    }

    public function getById(string $id): ReferralFeedback
    {
        return ReferralFeedback::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ReferralFeedback::class, $id);
    }

    public function getByIdForUpdate(string $id): ReferralFeedback
    {
        return ReferralFeedback::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ReferralFeedback::class, $id);
    }

    public function getLatestForReferral(string $referralId): ?ReferralFeedback
    {
        return ReferralFeedback::query()
            ->where('referral_id', $referralId)
            ->orderByDesc('sequence')
            ->first();
    }
}
