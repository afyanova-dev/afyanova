<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Domains\Patient\Domain\PatientAddressRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientAddressRepository}
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). `save` is inherited unchanged —
 * PatientAddress is a leaf aggregate.
 */
final class EloquentPatientAddressRepository extends EloquentRepository implements PatientAddressRepository
{
    protected function model(): string
    {
        return PatientAddress::class;
    }

    public function getById(string $id): PatientAddress
    {
        return $this->findById($id)
            ?? throw NotFound::aggregate(PatientAddress::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientAddress
    {
        return PatientAddress::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientAddress::class, $id);
    }

    public function findById(string $id): ?PatientAddress
    {
        return PatientAddress::query()->whereKey($id)->first();
    }
}
