<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;
use App\Domains\Laboratory\Domain\LabTestMethodReader;

final class GetLabTestMethodsHandler
{
    public function __construct(private readonly LabTestMethodReader $reader) {}

    /**
     * @return list<LabTestMethodSummary>
     */
    public function __invoke(GetLabTestMethods $query): array
    {
        return $this->reader->list();
    }
}
