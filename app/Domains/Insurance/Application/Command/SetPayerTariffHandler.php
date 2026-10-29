<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\PayerContractNotActive;
use App\Domains\Insurance\Domain\Exception\PayerTariffChangeConflict;
use App\Domains\Insurance\Domain\Exception\PayerTariffEffectiveDateNotAfterCurrent;
use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\PayerContractRepository;
use App\Domains\Insurance\Domain\PayerTariffRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `SetPayerTariff` (ADR-203; domain 21 §51–§55).
 *
 * Contract exists and is ACTIVE (`PayerContractNotActive` if not) → row-lock
 * the open tariff for the (contract, category) scope → strict-after guard +
 * `supersede()` → insert the new CURRENT row; partial-unique conflict →
 * `PayerTariffChangeConflict`.
 *
 * `PayerTariffRepository` is the FIRST repository parameter so the bus
 * resolves `PayerTariffPolicy`.
 */
final class SetPayerTariffHandler
{
    public function __construct(
        private readonly PayerTariffRepository $tariffs,
        private readonly PayerContractRepository $contracts,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetPayerTariff $command): void
    {
        $contract = $this->contracts->getById($command->contractId);

        if (! $contract->isActive()) {
            throw PayerContractNotActive::forContract($command->contractId, $contract->status);
        }

        $open = $this->tariffs->openRowForScopeForUpdate($command->contractId, $command->serviceCategory);

        if ($open !== null) {
            if (! $command->effectiveFrom->gt($open->effective_from)) {
                throw PayerTariffEffectiveDateNotAfterCurrent::dates(
                    $command->effectiveFrom->toDateString(),
                    $open->effective_from->toDateString(),
                );
            }
            $open->supersede($command->effectiveFrom);
            $this->tariffs->save($open);
        }

        $now = CarbonImmutable::now();

        $tariff = PayerTariff::open(
            id: $command->tariffId,
            tenantId: $this->context->tenantId(),
            contractId: $command->contractId,
            payerId: (string) $contract->payer_id,
            serviceCategory: $command->serviceCategory,
            amount: $command->amount,
            currency: $command->currency,
            effectiveFrom: $command->effectiveFrom,
            createdByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->tariffs->save($tariff);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ins_payer_tariffs_one_open_per_scope')) {
                throw PayerTariffChangeConflict::forScope($command->contractId, $command->serviceCategory);
            }
            throw $e;
        }
    }
}
