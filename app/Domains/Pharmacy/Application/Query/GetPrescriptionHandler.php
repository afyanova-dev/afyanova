<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\PrescriptionReader;

final class GetPrescriptionHandler
{
    public function __construct(private readonly PrescriptionReader $reader) {}

    public function __invoke(GetPrescription $query): PrescriptionSummary
    {
        return $this->reader->summary($query->prescriptionId);
    }
}
