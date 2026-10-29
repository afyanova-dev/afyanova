<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of {@see PayerContractReader} (IC §6.2; ADR-203).
 */
final class EloquentPayerContractReader implements PayerContractReader
{
    public function summary(string $contractId): PayerContractSummary
    {
        $contract = PayerContract::query()->whereKey($contractId)->first()
            ?? throw PayerContractNotFound::id($contractId);

        return $this->toSummary($contract);
    }

    public function forPayer(string $payerId, ?string $status = null): array
    {
        $query = PayerContract::query()
            ->where('payer_id', $payerId)
            ->orderByDesc('effective_from');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn (PayerContract $c) => $this->toSummary($c))->all());
    }

    public function activeContractFor(string $payerId, CarbonImmutable $onDate, ?string $facilityId = null): ?PayerContractSummary
    {
        $onDateString = $onDate->toDateString();

        $query = PayerContract::query()
            ->where('payer_id', $payerId)
            ->where('status', PayerContractStatus::ACTIVE)
            ->where('effective_from', '<=', $onDateString)
            ->where(function ($q) use ($onDateString): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $onDateString);
            });

        if ($facilityId !== null) {
            $query->where(function ($q) use ($facilityId): void {
                $q->where('facility_id', $facilityId)
                    ->orWhereNull('facility_id');
            });
            // Order so facility-specific contract comes before tenant-wide (facility_id IS NULL)
            $query->orderByRaw('CASE WHEN facility_id IS NOT NULL THEN 0 ELSE 1 END');
        } else {
            $query->whereNull('facility_id');
        }

        $query->orderByDesc('effective_from');

        $contract = $query->first();

        return $contract !== null ? $this->toSummary($contract) : null;
    }

    private function toSummary(PayerContract $c): PayerContractSummary
    {
        return new PayerContractSummary(
            id: (string) $c->id,
            contractNumber: (string) $c->contract_number,
            payerId: (string) $c->payer_id,
            name: (string) $c->name,
            reference: $c->reference !== null ? (string) $c->reference : null,
            facilityId: $c->facility_id !== null ? (string) $c->facility_id : null,
            status: (string) $c->status,
            effectiveFrom: $c->effective_from->toDateString(),
            effectiveTo: $c->effective_to?->toDateString(),
            terms: $c->terms !== null ? (string) $c->terms : null,
            terminationReason: $c->termination_reason !== null ? (string) $c->termination_reason : null,
            createdByUserId: (string) $c->created_by_user_id,
            activatedByUserId: $c->activated_by_user_id !== null ? (string) $c->activated_by_user_id : null,
            activatedAt: $c->activated_at?->toIso8601String(),
            terminatedByUserId: $c->terminated_by_user_id !== null ? (string) $c->terminated_by_user_id : null,
            terminatedAt: $c->terminated_at?->toIso8601String(),
            expiredAt: $c->expired_at?->toIso8601String(),
            version: (int) $c->version,
        );
    }
}
