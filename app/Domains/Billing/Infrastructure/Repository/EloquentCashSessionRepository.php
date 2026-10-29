<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\ValueObject\CashSessionStatus;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see CashSessionRepository} (IC §9; ADR-191 C6-A).
 */
final class EloquentCashSessionRepository extends EloquentRepository implements CashSessionRepository
{
    protected function model(): string
    {
        return CashSession::class;
    }

    public function getById(string $id): CashSession
    {
        return CashSession::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(CashSession::class, $id);
    }

    public function getByIdForUpdate(string $id): CashSession
    {
        return CashSession::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(CashSession::class, $id);
    }

    public function liveForCashierAtFacilityForUpdate(string $cashierUserId, string $facilityId): ?CashSession
    {
        return CashSession::query()
            ->where('cashier_user_id', $cashierUserId)
            ->where('facility_id', $facilityId)
            ->whereIn('status', CashSessionStatus::LIVE)
            ->lockForUpdate()
            ->first();
    }

    public function exists(string $id): bool
    {
        return CashSession::query()->whereKey($id)->exists();
    }
}
