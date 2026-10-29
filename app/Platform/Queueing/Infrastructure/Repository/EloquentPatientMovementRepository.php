<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Repository;

use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;

final class EloquentPatientMovementRepository implements PatientMovementRepository
{
    public function save(PatientMovement $movement): void
    {
        $movement->save();
    }

    /**
     * @return list<PatientMovement>
     */
    public function listForEncounter(string $encounterId): array
    {
        /** @var list<PatientMovement> $list */
        $list = PatientMovement::query()
            ->where('encounter_id', $encounterId)
            ->orderBy('occurred_at', 'asc')
            ->get()
            ->all();

        return $list;
    }

    public function findLatestForEncounter(string $encounterId): ?PatientMovement
    {
        /** @var PatientMovement|null $latest */
        $latest = PatientMovement::query()
            ->where('encounter_id', $encounterId)
            ->orderBy('occurred_at', 'desc')
            ->first();

        return $latest;
    }
}
