<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Service\ContractLifecycle;

/**
 * Orchestrates `TerminateContract` (ADR-176 D-C). Load the contract `FOR UPDATE`
 * → DRAFT | ACTIVE → TERMINATED (records `reason` ROW-ONLY) → save. One bus
 * transaction; no event.
 *
 * `ContractRepository` is the first repository parameter → `ContractPolicy::manage`.
 */
final class TerminateContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractLifecycle $lifecycle,
    ) {}

    public function __invoke(TerminateContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        $this->lifecycle->assertCanTerminate((string) $contract->status);

        $contract->markTerminated($command->actorId, $command->reason);

        $this->contracts->save($contract);
    }
}
