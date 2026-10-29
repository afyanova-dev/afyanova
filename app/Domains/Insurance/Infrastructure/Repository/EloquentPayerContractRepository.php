<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\PayerContractRepository;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of {@see PayerContractRepository} (IC §9; ADR-203).
 */
final class EloquentPayerContractRepository extends EloquentRepository implements PayerContractRepository
{
    protected function model(): string
    {
        return PayerContract::class;
    }

    public function getById(string $id): PayerContract
    {
        return PayerContract::query()->whereKey($id)->first()
            ?? throw PayerContractNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): PayerContract
    {
        return PayerContract::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw PayerContractNotFound::id($id);
    }

    public function findActivePastExpiry(CarbonImmutable $onDate, int $limit = 100): array
    {
        /** @var list<PayerContract> */
        return PayerContract::query()
            ->where('status', PayerContractStatus::ACTIVE)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<', $onDate->toDateString())
            ->orderBy('effective_to')
            ->limit($limit)
            ->get()
            ->all();
    }
}
