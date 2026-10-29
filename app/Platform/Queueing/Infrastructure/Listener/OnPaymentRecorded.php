<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Listener;

use App\Domains\Billing\Contracts\Event\PaymentRecorded;
use App\Platform\Queueing\Application\Command\ReleasePatientFinancialHoldCommand;
use App\Shared\Bus\CommandBus;

final class OnPaymentRecorded
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(PaymentRecorded $event): void
    {
        $this->bus->dispatch(new ReleasePatientFinancialHoldCommand(
            patientId: $event->patientId,
            paymentId: $event->paymentId,
        ));
    }
}
