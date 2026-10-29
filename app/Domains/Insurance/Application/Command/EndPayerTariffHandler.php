<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\PayerTariffEffectiveDateNotAfterCurrent;
use App\Domains\Insurance\Domain\PayerTariffRepository;

/**
 * Orchestrates `EndPayerTariff` (ADR-203).
 */
final class EndPayerTariffHandler
{
    public function __construct(
        private readonly PayerTariffRepository $tariffs,
    ) {}

    public function __invoke(EndPayerTariff $command): void
    {
        $tariff = $this->tariffs->getByIdForUpdate($command->tariffId);

        if (! $command->effectiveTo->gt($tariff->effective_from)) {
            throw PayerTariffEffectiveDateNotAfterCurrent::dates(
                $command->effectiveTo->toDateString(),
                $tariff->effective_from->toDateString(),
            );
        }

        $tariff->end($command->effectiveTo);

        $this->tariffs->save($tariff);
    }
}
