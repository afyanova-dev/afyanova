<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\PayerContractRepository;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ExpirePayerContract` (ADR-203).
 *
 * Defensive no-op unless the contract is ACTIVE AND strictly past its
 * `effective_to` date.
 */
final class ExpirePayerContractHandler
{
    public function __construct(
        private readonly PayerContractRepository $contracts,
    ) {}

    public function __invoke(ExpirePayerContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        if ($contract->status !== PayerContractStatus::ACTIVE
            || $contract->effective_to === null
            || $contract->effective_to->toDateString() >= CarbonImmutable::now()->toDateString()) {
            return;
        }

        $contract->expire(CarbonImmutable::now());

        $this->contracts->save($contract);
    }
}
