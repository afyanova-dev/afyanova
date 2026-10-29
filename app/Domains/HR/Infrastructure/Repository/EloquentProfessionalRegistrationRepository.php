<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\ProfessionalRegistrationNotFoundException;
use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Domains\HR\Domain\ProfessionalRegistrationRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of ProfessionalRegistrationRepository (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final class EloquentProfessionalRegistrationRepository extends EloquentRepository implements ProfessionalRegistrationRepository
{
    protected function model(): string
    {
        return ProfessionalRegistration::class;
    }

    public function getById(string $id): ProfessionalRegistration
    {
        return ProfessionalRegistration::query()->whereKey($id)->first()
            ?? throw ProfessionalRegistrationNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): ProfessionalRegistration
    {
        return ProfessionalRegistration::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw ProfessionalRegistrationNotFoundException::forId($id);
    }

    public function existsByLicence(string $council, string $licenceNumber, ?string $excludeId = null): bool
    {
        $query = ProfessionalRegistration::query()
            ->where('council', $council)
            ->where('licence_number', $licenceNumber);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function existsById(string $id): bool
    {
        return ProfessionalRegistration::query()->whereKey($id)->exists();
    }
}
