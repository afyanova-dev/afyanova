<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

final readonly class ReleasePatientFinancialHoldCommand
{
    public function __construct(
        public string $patientId,
        public string $paymentId,
    ) {}
}
