<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountStatus;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see FinancialAccountRepository} (IC §9; ADR-187 D-B).
 */
final class EloquentFinancialAccountRepository extends EloquentRepository implements FinancialAccountRepository
{
    protected function model(): string
    {
        return FinancialAccount::class;
    }

    public function getById(string $id): FinancialAccount
    {
        return FinancialAccount::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(FinancialAccount::class, $id);
    }

    public function getByIdForUpdate(string $id): FinancialAccount
    {
        return FinancialAccount::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(FinancialAccount::class, $id);
    }

    public function hasOpenAccountForPatientAtFacility(string $patientId, string $facilityId): bool
    {
        return FinancialAccount::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->whereNotIn('status', FinancialAccountStatus::TERMINAL)
            ->exists();
    }

    public function openAccountForPatientAtFacilityForUpdate(string $patientId, string $facilityId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('patient_id', $patientId)
            ->where('facility_id', $facilityId)
            ->whereNotIn('status', FinancialAccountStatus::TERMINAL)
            ->lockForUpdate()
            ->first();
    }
}
