<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Exception\ContractSelfActivationNotPermitted;
use App\Domains\Procurement\Domain\Service\ContractLifecycle;

/**
 * Orchestrates `ActivateContract` (ADR-176 D-C). Load the contract `FOR UPDATE`
 * → it must be DRAFT → the actor must not be its creator (§24 / §228 SoD) →
 * DRAFT → ACTIVE → save. One bus transaction; no event.
 *
 * `ContractRepository` is the first repository parameter → `ContractPolicy::manage`.
 */
final class ActivateContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractLifecycle $lifecycle,
    ) {}

    public function __invoke(ActivateContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        $this->lifecycle->assertCanActivate((string) $contract->status);

        if ((string) $contract->created_by_user_id === $command->actorId) {
            throw ContractSelfActivationNotPermitted::forContract((string) $contract->id);
        }

        $contract->markActive($command->actorId);

        $this->contracts->save($contract);
    }
}
