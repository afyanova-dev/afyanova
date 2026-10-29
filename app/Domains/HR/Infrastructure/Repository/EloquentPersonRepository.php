<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\PersonNotFoundException;
use App\Domains\HR\Domain\Model\Person;
use App\Domains\HR\Domain\PersonRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of PersonRepository (25-hr-and-workforce-domain.md §2; ADR-206).
 */
final class EloquentPersonRepository extends EloquentRepository implements PersonRepository
{
    protected function model(): string
    {
        return Person::class;
    }

    public function getById(string $id): Person
    {
        return Person::query()->whereKey($id)->first()
            ?? throw PersonNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Person
    {
        return Person::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw PersonNotFoundException::forId($id);
    }

    public function existsById(string $id): bool
    {
        return Person::query()->whereKey($id)->exists();
    }

    public function existsByNationalId(string $nationalId): bool
    {
        return Person::query()->where('national_id', $nationalId)->exists();
    }
}
