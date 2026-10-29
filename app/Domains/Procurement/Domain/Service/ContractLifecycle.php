<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalContractTransition;
use App\Domains\Procurement\Domain\Model\Contract;

/**
 * Purchase-contract lifecycle rules (19-procurement-domain.md §40, §194;
 * ADR-176 D-B). Pure domain logic, no I/O.
 *
 *   DRAFT -> ACTIVE            (ActivateContract)
 *   ACTIVE -> ACTIVE           (AmendContract — in place, guarded; ADR-178 D-D)
 *   ACTIVE -> SUPERSEDED       (RenewContract flips the replaced contract; ADR-178 D-D)
 *   DRAFT | ACTIVE -> TERMINATED  (TerminateContract)
 *
 * There is NO EXPIRED transition — §194 (Inv 32) is enforced check-at-use in
 * `CreatePurchaseOrderHandler` (`Contract::isUsable()`). SUPERSEDED is terminal
 * (not terminable / re-activatable / amendable).
 */
final class ContractLifecycle
{
    public function assertCanActivate(string $status): void
    {
        if ($status !== Contract::STATUS_DRAFT) {
            throw IllegalContractTransition::fromStatus('ActivateContract', $status);
        }
    }

    public function assertCanTerminate(string $status): void
    {
        if (! in_array($status, [Contract::STATUS_DRAFT, Contract::STATUS_ACTIVE], true)) {
            throw IllegalContractTransition::fromStatus('TerminateContract', $status);
        }
    }

    public function assertCanAmend(string $status): void
    {
        if ($status !== Contract::STATUS_ACTIVE) {
            throw IllegalContractTransition::fromStatus('AmendContract', $status);
        }
    }

    public function assertCanSupersede(string $status): void
    {
        if ($status !== Contract::STATUS_ACTIVE) {
            throw IllegalContractTransition::fromStatus('RenewContract', $status);
        }
    }
}
