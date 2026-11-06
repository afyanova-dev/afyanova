<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;

interface LabTestMethodReader
{
    /**
     * @return list<LabTestMethodSummary>
     */
    public function list(): array;
}
