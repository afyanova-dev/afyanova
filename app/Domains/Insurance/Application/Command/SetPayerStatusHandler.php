<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\PayerRepository;

/**
 * Orchestrates `SetPayerStatus` (ADR-198 D-G). `getByIdForUpdate` →
 * `activate()` / `deactivate()` → save.
 */
final class SetPayerStatusHandler
{
    public function __construct(private readonly PayerRepository $payers) {}

    public function __invoke(SetPayerStatus $command): void
    {
        $payer = $this->payers->getByIdForUpdate($command->payerId);

        if ($command->status === Payer::STATUS_ACTIVE) {
            $payer->activate();
        } else {
            $payer->deactivate($command->reason);
        }

        $this->payers->save($payer);
    }
}
