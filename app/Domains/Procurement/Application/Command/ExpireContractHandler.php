<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Model\Contract;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ExpireContract` (ADR-179 D-C / D-D).
 *
 * Defensive no-op unless the contract is ACTIVE AND strictly past its
 * `expiry_date` — a sweep may race a human action (`RenewContract` flips ACTIVE
 * → SUPERSEDED; `TerminateContract` → TERMINATED), and a double sweep is safe.
 * `ContractLifecycle::assertCanExpire` is deliberately NOT used (the guard is a
 * TOCTOU re-check of the console's own query, not a domain transition rule — the
 * `ExpireReferralHandler` note). Flips `status` to `EXPIRED`; records NO event
 * (the dirtied `status` bumps `version` on save).
 *
 * `ContractRepository` is the first repository parameter → `ContractPolicy`
 * would resolve, but `ExpireContract` is not `AuthorizesAgainst`, so the bus
 * skips the check (system command).
 */
final class ExpireContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
    ) {}

    public function __invoke(ExpireContract $command): void
    {
        $contract = $this->contracts->getByIdForUpdate($command->contractId);

        if ((string) $contract->status !== Contract::STATUS_ACTIVE
            || $contract->expiry_date->toDateString() >= CarbonImmutable::now()->toDateString()) {
            return; // not a candidate — already resolved, or still within its term
        }

        $contract->markExpired();

        $this->contracts->save($contract);
    }
}
