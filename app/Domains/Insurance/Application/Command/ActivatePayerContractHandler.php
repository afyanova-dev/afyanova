<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\PayerContractRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ActivatePayerContract` (ADR-203).
 */
final class ActivatePayerContractHandler
{
    public function __construct(
        private readonly PayerContractRepository $contracts,
    ) {}

    public function __invoke(ActivatePayerContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        $contract->activate($command->actorId, CarbonImmutable::now());

        $this->contracts->save($contract);
    }
}
