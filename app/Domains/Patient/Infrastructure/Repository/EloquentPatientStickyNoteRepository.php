<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentPatientStickyNoteRepository extends EloquentRepository implements PatientStickyNoteRepository
{
    protected function model(): string
    {
        return PatientStickyNote::class;
    }

    public function getById(string $id): PatientStickyNote
    {
        return PatientStickyNote::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PatientStickyNote::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientStickyNote
    {
        return PatientStickyNote::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientStickyNote::class, $id);
    }

    public function findActiveForPatient(string $patientId): ?PatientStickyNote
    {
        return PatientStickyNote::query()
            ->where('patient_id', $patientId)
            ->whereNull('archived_at')
            ->first();
    }

    public function findActiveForPatientForUpdate(string $patientId): ?PatientStickyNote
    {
        return PatientStickyNote::query()
            ->where('patient_id', $patientId)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();
    }
}
