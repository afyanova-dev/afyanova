<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\PayerRepository;

/**
 * Orchestrates `UpdatePayer` (ADR-198 D-G). `getByIdForUpdate` → `rename()` →
 * save.
 */
final class UpdatePayerHandler
{
    public function __construct(private readonly PayerRepository $payers) {}

    public function __invoke(UpdatePayer $command): void
    {
        $payer = $this->payers->getByIdForUpdate($command->payerId);

        $payer->rename(
            $command->name,
            $command->contactName,
            $command->contactEmail,
            $command->contactPhone,
        );

        $this->payers->save($payer);
    }
}
