<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\PayerContractRepository;
use App\Domains\Insurance\Domain\PayerReader;
use App\Domains\Insurance\Infrastructure\Sequence\ContractNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CreatePayerContract` (ADR-203; domain 21 §49).
 *
 * Payer exists in tenant (`PayerNotFound` if absent) → mints `PCT-%08d` via
 * `ContractNumberSequence` → builds `PayerContract` in DRAFT status → saves.
 *
 * `PayerContractRepository` is the FIRST repository parameter so the bus
 * resolves `PayerContractPolicy`.
 */
final class CreatePayerContractHandler
{
    public function __construct(
        private readonly PayerContractRepository $contracts,
        private readonly PayerReader $payers,
        private readonly ContractNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePayerContract $command): void
    {
        $this->payers->summary($command->payerId);

        $now = CarbonImmutable::now();
        $contractNumber = $this->sequence->next($this->context->tenantId());

        $contract = PayerContract::createDraft(
            id: $command->contractId,
            tenantId: $this->context->tenantId(),
            contractNumber: $contractNumber,
            payerId: $command->payerId,
            name: $command->name,
            reference: $command->reference,
            facilityId: $command->facilityId,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            terms: $command->terms,
            createdByUserId: $command->actorId,
            now: $now,
        );

        $this->contracts->save($contract);
    }
}
