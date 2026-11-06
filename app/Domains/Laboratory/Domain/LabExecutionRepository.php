<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Shared\Domain\Repository;

interface LabExecutionRepository extends Repository
{
    public function getById(string $id): LabExecution;

    public function getByIdForUpdate(string $id): LabExecution;
}
