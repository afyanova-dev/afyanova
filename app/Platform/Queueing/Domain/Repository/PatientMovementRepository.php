<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Repository;

use App\Platform\Queueing\Domain\Model\PatientMovement;

interface PatientMovementRepository
{
    public function save(PatientMovement $movement): void;

    /**
     * @return list<PatientMovement>
     */
    public function listForEncounter(string $encounterId): array;

    public function findLatestForEncounter(string $encounterId): ?PatientMovement;
}
