<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;
use App\Domains\Insurance\Domain\Exception\PayerTariffNotFound;
use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Domains\Insurance\Domain\PayerTariffReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Eloquent implementation of {@see PayerTariffReader} (IC §6.2; ADR-203).
 */
final class EloquentPayerTariffReader implements PayerTariffReader
{
    public function __construct(
        private readonly PayerContractReader $contracts,
    ) {}

    public function summary(string $tariffId): PayerTariffSummary
    {
        $tariff = PayerTariff::query()->whereKey($tariffId)->first()
            ?? throw PayerTariffNotFound::id($tariffId);

        return $this->toSummary($tariff);
    }

    public function forContract(string $contractId, ?string $serviceCategory = null): array
    {
        $query = PayerTariff::query()->where('contract_id', $contractId);

        if ($serviceCategory !== null) {
            $query->where('service_category', strtoupper(trim($serviceCategory)));
        }

        return array_values(
            $query->orderByDesc('effective_from')->orderBy('id')->get()
                ->map(fn (PayerTariff $t): PayerTariffSummary => $this->toSummary($t))
                ->all(),
        );
    }

    public function effectiveTariffForContract(string $contractId, string $serviceCategory, string $onDate): ?PayerTariffSummary
    {
        $tariff = PayerTariff::query()
            ->where('contract_id', $contractId)
            ->where('service_category', strtoupper(trim($serviceCategory)))
            ->where('effective_from', '<=', $onDate)
            ->where(function (Builder $q) use ($onDate): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $onDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $tariff !== null ? $this->toSummary($tariff) : null;
    }

    public function effectiveTariffForPayer(string $payerId, string $serviceCategory, string $onDate, ?string $facilityId = null): ?PayerTariffSummary
    {
        $date = CarbonImmutable::parse($onDate);
        $contract = $this->contracts->activeContractFor($payerId, $date, $facilityId);

        if ($contract === null) {
            return null;
        }

        return $this->effectiveTariffForContract($contract->id, $serviceCategory, $onDate);
    }

    private function toSummary(PayerTariff $t): PayerTariffSummary
    {
        return new PayerTariffSummary(
            id: (string) $t->id,
            contractId: (string) $t->contract_id,
            payerId: (string) $t->payer_id,
            serviceCategory: (string) $t->service_category,
            amount: Money::of((string) $t->amount),
            currency: (string) $t->currency,
            effectiveFrom: $t->effective_from->toDateString(),
            effectiveTo: $t->effective_to?->toDateString(),
            status: (string) $t->status,
            createdByUserId: $t->created_by_user_id !== null ? (string) $t->created_by_user_id : null,
            version: (int) $t->version,
        );
    }
}
