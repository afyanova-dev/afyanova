<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\PayerContractRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `TerminatePayerContract` (ADR-203).
 */
final class TerminatePayerContractHandler
{
    public function __construct(
        private readonly PayerContractRepository $contracts,
    ) {}

    public function __invoke(TerminatePayerContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        $contract->terminate($command->actorId, $command->reason, CarbonImmutable::now());

        $this->contracts->save($contract);
    }
}
