<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\BillingServiceSummary;
use App\Domains\Billing\Contracts\Dto\TariffHistoryEntry;
use App\Domains\Billing\Contracts\Dto\TariffResolution;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\Exception\BillingServiceNotFound;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\Service\TariffResolver;
use App\Domains\Billing\Domain\TariffCandidate;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Eloquent implementation of {@see BillingCatalogReader} (IC §6.2 / §9;
 * ADR-188 D-D / D-E). The tenant-wide default tariff is the row whose
 * `facility_id` equals the tenant id (the sentinel — ADR-188 F4). Effective
 * dates are compared as the server's current date (single national zone;
 * multi-zone is a future concern).
 */
final class EloquentBillingCatalogReader implements BillingCatalogReader
{
    public function __construct(
        private readonly TariffResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function serviceSummary(string $billingServiceId): BillingServiceSummary
    {
        $service = BillingService::query()->whereKey($billingServiceId)->first()
            ?? throw BillingServiceNotFound::id($billingServiceId);

        return $this->toSummary($service);
    }

    public function serviceExists(string $billingServiceId): bool
    {
        return BillingService::query()->whereKey($billingServiceId)->exists();
    }

    public function categoryForCode(string $code): ?string
    {
        $category = BillingService::query()->where('code', $code)->value('category');

        return $category !== null ? (string) $category : null;
    }

    public function searchServices(?string $category, ?string $status): array
    {
        $query = BillingService::query();

        if ($category !== null) {
            $query->where('category', strtoupper(trim($category)));
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (BillingService $s): BillingServiceSummary => $this->toSummary($s))
                ->all()
        );
    }

    public function resolveTariff(string $billingServiceId, ?string $facilityId, ?string $onDate): TariffResolution
    {
        $service = BillingService::query()->whereKey($billingServiceId)->first()
            ?? throw BillingServiceNotFound::id($billingServiceId);

        return $this->resolveForService($service, $facilityId, $this->date($onDate));
    }

    public function priceList(?string $facilityId, ?string $onDate): array
    {
        $date = $this->date($onDate);

        return array_values(
            BillingService::query()->orderBy('category')->orderBy('code')->orderBy('id')->get()
                ->map(fn (BillingService $s): TariffResolution => $this->resolveForService($s, $facilityId, $date))
                ->all()
        );
    }

    private function resolveForService(BillingService $service, ?string $facilityId, string $date): TariffResolution
    {
        $sentinel = $this->context->tenantId();

        $facilityCandidate = ($facilityId !== null && $facilityId !== $sentinel)
            ? $this->candidate((string) $service->id, $facilityId, $date)
            : null;

        $defaultCandidate = $this->candidate((string) $service->id, $sentinel, $date);

        return $this->resolver->resolve(
            billingServiceId: (string) $service->id,
            facilityId: $facilityId,
            serviceStatus: (string) $service->status,
            facilityCandidate: $facilityCandidate,
            tenantDefaultCandidate: $defaultCandidate,
        );
    }

    private function candidate(string $billingServiceId, string $facilityId, string $date): ?TariffCandidate
    {
        $row = Tariff::query()
            ->where('billing_service_id', $billingServiceId)
            ->where('facility_id', $facilityId)
            ->where('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new TariffCandidate(
            tariffRowId: (string) $row->id,
            amount: Money::of((string) $row->amount),
            currency: (string) $row->currency,
            effectiveFrom: $row->effective_from->toDateString(),
        );
    }

    private function date(?string $onDate): string
    {
        return $onDate !== null && trim($onDate) !== ''
            ? CarbonImmutable::parse($onDate)->toDateString()
            : CarbonImmutable::now()->toDateString();
    }

    public function tariffHistory(string $billingServiceId): array
    {
        $sentinel = $this->context->tenantId();

        return Tariff::query()
            ->where('billing_service_id', $billingServiceId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Tariff $t): TariffHistoryEntry => new TariffHistoryEntry(
                tariffId: (string) $t->id,
                billingServiceId: (string) $t->billing_service_id,
                resolvedScope: (string) $t->facility_id === $sentinel ? 'TENANT_DEFAULT' : 'FACILITY',
                amount: Money::of((string) $t->amount),
                currency: (string) $t->currency,
                effectiveFrom: CarbonImmutable::parse((string) $t->effective_from)->toDateString(),
                effectiveTo: $t->effective_to !== null ? CarbonImmutable::parse((string) $t->effective_to)->toDateString() : null,
                status: (string) $t->status,
                recordedAt: CarbonImmutable::parse((string) $t->recorded_at)->toIso8601String(),
            ))
            ->all();
    }

    private function toSummary(BillingService $s): BillingServiceSummary
    {
        return new BillingServiceSummary(
            billingServiceId: (string) $s->id,
            code: (string) $s->code,
            name: (string) $s->name,
            category: (string) $s->category,
            status: (string) $s->status,
            occurredAt: $s->occurred_at->toIso8601String(),
            recordedAt: $s->recorded_at->toIso8601String(),
        );
    }
}
